import { drawLabel, toMonochrome } from "../render";
import { zip } from "../zip";

/**
 * Printing per driver of the printer (App\Enums\PrinterDriver). Every driver takes labels drawn
 * by render.ts; a new driver (Brother, Zebra) adds a case here and its own file.
 *
 * - paper: print dialog of the browser, one label per page or an A4 sheet
 * - niimbot: directly by Bluetooth or USB (niimbot.ts), otherwise as png in the dots of the printer
 */

// resolution of paper prints, about 300 dpi
const PAPER_PX_PER_MM = 12;

export type PrintLabel = {
  template: { width_mm: number; height_mm: number; elements: LabelElement[] };
  data: LabelData;
};

/**
 * Pixels per mm the printer prints with.
 */
export const pxPerMmOf = (printer: PrinterData) =>
  printer.driver === "niimbot"
    ? (printer.model?.dpi ?? 203) / 25.4
    : PAPER_PX_PER_MM;

/**
 * Draw a label as the printer gets it. For niimbot printers in its dots, turned and shifted by
 * the settings of the printer and in hard black and white; "crop" cuts it to the print head (direct
 * print), without it the image keeps the full label size for the Niimbot app (50 × 30 mm → 400 × 240).
 */
export const renderForPrinter = (
  label: PrintLabel,
  printer: PrinterData,
  images: Map<string, HTMLImageElement | null>,
  logoUrl: string | null,
  crop = false,
) => {
  const k = pxPerMmOf(printer);
  const canvas = document.createElement("canvas");
  const problems = drawLabel(canvas, label.template, label.data, {
    pxPerMm: k,
    images,
    logoUrl,
  });

  if (printer.driver !== "niimbot") {
    return { canvas, problems };
  }

  const prepared = transform(
    canvas,
    printer.settings.rotation ?? 0,
    (printer.settings.offset_x ?? 0) * k,
    (printer.settings.offset_y ?? 0) * k,
  );
  const head = Math.round((printer.model?.max_width_mm ?? Infinity) * k);
  const result =
    crop && prepared.width > head ? cropMiddle(prepared, head) : prepared;
  toMonochrome(result);

  return { canvas: result, problems };
};

/**
 * Turn a canvas by 0, 90, 180 or 270 degrees and shift its content (white where nothing is).
 */
const transform = (
  source: HTMLCanvasElement,
  rotation: number,
  offsetX: number,
  offsetY: number,
) => {
  const turned = rotation === 90 || rotation === 270;
  const canvas = document.createElement("canvas");
  canvas.width = turned ? source.height : source.width;
  canvas.height = turned ? source.width : source.height;

  const context = canvas.getContext("2d", { willReadFrequently: true })!;
  context.fillStyle = "#fff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.translate(canvas.width / 2 + offsetX, canvas.height / 2 + offsetY);
  context.rotate((rotation * Math.PI) / 180);
  context.drawImage(source, -source.width / 2, -source.height / 2);

  return canvas;
};

/**
 * Cut a canvas to the given width around its middle.
 */
const cropMiddle = (source: HTMLCanvasElement, width: number) => {
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = source.height;
  canvas
    .getContext("2d", { willReadFrequently: true })!
    .drawImage(source, -Math.round((source.width - width) / 2), 0);
  return canvas;
};

/**
 * Print on paper with the print dialog of the browser: one label per page in its own size, or
 * labels in a grid on A4 sheets (settings of the printer).
 */
export const printPaper = async (
  canvases: {
    canvas: HTMLCanvasElement;
    width_mm: number;
    height_mm: number;
  }[],
  copies: number,
  printer: PrinterData,
) => {
  const labels = canvases.flatMap((entry) =>
    Array.from({ length: copies }, () => entry),
  );
  const settings = printer.settings;
  const image = (entry: (typeof labels)[number], style = "") =>
    `<img src="${entry.canvas.toDataURL("image/png")}" style="width:${entry.width_mm}mm;height:${entry.height_mm}mm;${style}">`;

  let body: string;
  let page: string;
  if (settings.page === "sheet") {
    const columns = settings.columns ?? 2;
    const perSheet = columns * (settings.rows ?? 8);
    page = "size: A4; margin: 0;";
    body = Array.from(
      { length: Math.ceil(labels.length / perSheet) },
      (_, sheet) =>
        `<section>${labels
          .slice(sheet * perSheet, (sheet + 1) * perSheet)
          .map((entry, index) => {
            const left =
              (settings.margin_left ?? 0) +
              (index % columns) * (entry.width_mm + (settings.gap_x ?? 0));
            const top =
              (settings.margin_top ?? 0) +
              Math.floor(index / columns) *
                (entry.height_mm + (settings.gap_y ?? 0));
            return image(
              entry,
              `position:absolute;left:${left}mm;top:${top}mm;`,
            );
          })
          .join("")}</section>`,
    ).join("");
  } else {
    // a label printer at the pc gets one page per label in the size of the first label
    page = `size: ${labels[0].width_mm}mm ${labels[0].height_mm}mm; margin: 0;`;
    body = labels.map((entry) => `<section>${image(entry)}</section>`).join("");
  }

  const frame = document.createElement("iframe");
  frame.style.cssText =
    "position:fixed;width:0;height:0;border:0;visibility:hidden";
  document.body.append(frame);
  const doc = frame.contentDocument!;
  doc.open();
  doc.write(`<!doctype html><html><head><title>Etiketten</title><style>
    @page { ${page} }
    html, body { margin: 0; padding: 0; }
    section { position: relative; page-break-after: always; break-after: page; ${settings.page === "sheet" ? "width: 210mm; height: 297mm;" : ""} overflow: hidden; }
    section:last-child { page-break-after: auto; break-after: auto; }
    img { display: block; }
  </style></head><body>${body}</body></html>`);
  doc.close();

  await Promise.all(
    [...doc.images].map((element) => element.decode().catch(() => undefined)),
  );
  frame.contentWindow!.focus();
  frame.contentWindow!.print();
  // the dialog blocks until it is closed in most browsers; remove the frame a bit later for the others
  setTimeout(() => frame.remove(), 60_000);
};

/**
 * Download labels as png, several in a zip file.
 */
export const downloadImages = async (
  canvases: { canvas: HTMLCanvasElement; name: string }[],
) => {
  const blobOf = (canvas: HTMLCanvasElement) =>
    new Promise<Blob>((resolve) =>
      canvas.toBlob((blob) => resolve(blob!), "image/png"),
    );

  const save = (blob: Blob, name: string) => {
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 10_000);
  };

  if (canvases.length === 1) {
    save(await blobOf(canvases[0].canvas), `${canvases[0].name}.png`);
    return;
  }

  const files = await Promise.all(
    canvases.map(async (entry) => ({
      name: `${entry.name}.png`,
      data: new Uint8Array(await (await blobOf(entry.canvas)).arrayBuffer()),
    })),
  );
  save(zip(files), "Etiketten.zip");
};
