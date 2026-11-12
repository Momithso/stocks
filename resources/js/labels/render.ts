import bwipjs from "bwip-js/browser";

/**
 * Drawing of labels. One function draws a label on a canvas in any resolution: the editor shows it
 * enlarged, the drivers print it in the dots of the printer. So the preview is exactly the print.
 *
 * Positions and sizes of the elements are in mm from the top left corner (see
 * App\Support\Labels\LabelElements). Every element is drawn in its own box; a rotated element is
 * turned around the middle of its box.
 */

// the font is bundled with the app (@fontsource-variable/inter), so labels look the same everywhere
export const FONT_FAMILY = '"Inter Variable", sans-serif';

const MM_PER_PT = 25.4 / 72;
// smallest font size when shrinking a too long text
const MIN_FONT_PT = 4;
// a code with smaller modules is hard to read for phone cameras
const MIN_MODULE_MM = 0.4;

export type DrawOptions = {
  // resolution: pixels per mm of the label
  pxPerMm: number;
  // loaded images by url (loadImages)
  images: Map<string, HTMLImageElement | null>;
  logoUrl: string | null;
  // editor: show missing images as grey boxes
  placeholders?: boolean;
};

const ELEMENT_NAMES: Record<LabelElement["type"], string> = {
  text: "Text",
  code: "Code",
  logo: "Logo",
  image: "Bild",
  photo: "Foto",
  line: "Linie",
  rect: "Rahmen",
};

/**
 * Short name of an element for lists and warnings, e.g. Text „{name}“.
 */
export const elementName = (element: LabelElement) =>
  element.type === "text"
    ? `Text „${(element.text ?? "").slice(0, 24)}“`
    : ELEMENT_NAMES[element.type];

/**
 * Put the values into the variables of a text; unknown variables stay as they are.
 */
export const fillVariables = (text: string, values: Record<string, string>) =>
  text.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in values ? values[name] : match,
  );

/**
 * Elements that are left out for this label: a text with an empty {hint}, a photo without photo.
 */
export const isHidden = (element: LabelElement, data: LabelData) =>
  (element.type === "text" &&
    /\{hint\}/.test(element.text ?? "") &&
    !data.values.hint) ||
  (element.type === "photo" && !data.photo_url);

/**
 * Url of the image of an element, null for elements without image.
 */
export const imageUrlOf = (
  element: LabelElement,
  data: LabelData,
  logoUrl: string | null,
): string | null => {
  switch (element.type) {
    case "logo":
      return logoUrl;
    case "photo":
      return data.photo_url;
    case "image":
      return element.path
        ? useSurface().url(
            `/labels/images/${element.path.replace(/^labels\//, "")}`,
          )
        : null;
    default:
      return null;
  }
};

const imageCache = new Map<string, Promise<HTMLImageElement | null>>();

/**
 * Load the images and the font of labels before drawing (drawing itself is synchronous).
 */
export const loadImages = async (
  templates: { elements: LabelElement[] }[],
  labels: LabelData[],
  logoUrl: string | null,
) => {
  const urls = new Set<string>();
  for (const template of templates) {
    for (const element of template.elements) {
      for (const data of labels) {
        const url = imageUrlOf(element, data, logoUrl);
        if (url) {
          urls.add(url);
        }
      }
    }
  }

  await Promise.all([
    document.fonts.load(`400 16px ${FONT_FAMILY}`),
    document.fonts.load(`700 16px ${FONT_FAMILY}`),
  ]);

  const images = new Map<string, HTMLImageElement | null>();
  await Promise.all(
    [...urls].map(async (url) => {
      if (!imageCache.has(url)) {
        imageCache.set(
          url,
          new Promise((resolve) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => resolve(null);
            image.src = url;
          }),
        );
      }
      images.set(url, await imageCache.get(url)!);
    }),
  );

  return images;
};

const matrixCache = new Map<
  string,
  { columns: number; rows: number; bits: number[] }
>();

/**
 * Modules of a qr code or data matrix (1 = black), made by bwip-js.
 */
export const matrixOf = (symbology: "qrcode" | "datamatrix", text: string) => {
  const key = `${symbology}:${text}`;
  if (!matrixCache.has(key)) {
    const [symbol] = bwipjs.raw(
      symbology,
      text,
      symbology === "qrcode" ? "eclevel=M" : "",
    ) as { pixs: number[]; pixx: number; pixy: number }[];
    matrixCache.set(key, {
      columns: symbol.pixx,
      rows: symbol.pixy,
      bits: symbol.pixs,
    });
  }
  return matrixCache.get(key)!;
};

/**
 * Draw a label on a canvas; the canvas gets the size of the label in the given resolution.
 * Returns problems found while drawing, e.g. a cut text.
 */
export const drawLabel = (
  canvas: HTMLCanvasElement,
  template: { width_mm: number; height_mm: number; elements: LabelElement[] },
  data: LabelData,
  options: DrawOptions,
): string[] => {
  const k = options.pxPerMm;
  canvas.width = Math.round(template.width_mm * k);
  canvas.height = Math.round(template.height_mm * k);

  // thermal printers read the pixels back for black and white
  const context = canvas.getContext("2d", { willReadFrequently: true })!;
  context.fillStyle = "#fff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.fillStyle = "#000";
  context.strokeStyle = "#000";

  const problems: string[] = [];
  for (const element of template.elements) {
    if (isHidden(element, data)) {
      continue;
    }

    // own coordinates per element: origin in the middle of its box, turned by its rotation
    const turned = element.rotation === 90 || element.rotation === 270;
    const width = (turned ? element.h : element.w) * k;
    const height = (turned ? element.w : element.h) * k;
    context.save();
    context.translate(
      (element.x + element.w / 2) * k,
      (element.y + element.h / 2) * k,
    );
    context.rotate((element.rotation * Math.PI) / 180);
    context.translate(-width / 2, -height / 2);

    switch (element.type) {
      case "text": {
        const problem = drawText(context, element, data, width, height, k);
        if (problem) {
          problems.push(problem);
        }
        break;
      }
      case "code":
        drawCode(context, element, data, width, height);
        break;
      case "logo":
      case "image":
      case "photo":
        drawImage(context, element, data, width, height, options);
        break;
      case "line": {
        const stroke = (element.stroke ?? 0.3) * k;
        context.fillRect(0, (height - stroke) / 2, width, stroke);
        break;
      }
      case "rect": {
        const stroke = (element.stroke ?? 0.3) * k;
        context.lineWidth = stroke;
        context.strokeRect(
          stroke / 2,
          stroke / 2,
          width - stroke,
          height - stroke,
        );
        break;
      }
    }
    context.restore();
  }

  return problems;
};

/**
 * Text with line breaks at spaces, shrunk or cut when it does not fit.
 */
const drawText = (
  context: CanvasRenderingContext2D,
  element: LabelElement,
  data: LabelData,
  width: number,
  height: number,
  k: number,
): string | null => {
  const text = fillVariables(element.text ?? "", data.values).trim();
  if (!text) {
    return null;
  }

  const weight = element.bold ? 700 : 400;
  let size = (element.font_size ?? 8) * MM_PER_PT * k;
  const minSize = MIN_FONT_PT * MM_PER_PT * k;
  let lines: string[];

  // shrink until the text fits without breaking a word, but not below the smallest size
  for (;;) {
    context.font = `${weight} ${size}px ${FONT_FAMILY}`;
    const wrapped = wrap(context, text, width);
    lines = wrapped.lines;
    const fits = lines.length * size * 1.2 <= height + 0.01 && !wrapped.broken;
    if (fits || element.overflow === "clip" || size <= minSize) {
      break;
    }
    size = Math.max(minSize, size * 0.94);
  }

  const lineHeight = size * 1.2;
  const visible = Math.max(1, Math.floor((height + 0.01) / lineHeight));
  const total = Math.min(lines.length, visible) * lineHeight;
  const top =
    element.valign === "bottom"
      ? height - total
      : element.valign === "middle"
        ? (height - total) / 2
        : 0;

  context.save();
  context.beginPath();
  context.rect(0, 0, width, height);
  context.clip();
  context.textBaseline = "alphabetic";
  context.textAlign = element.align ?? "left";
  const x =
    element.align === "center"
      ? width / 2
      : element.align === "right"
        ? width
        : 0;
  lines.slice(0, visible).forEach((line, index) => {
    // the baseline sits at about 80 % of the line height
    context.fillText(line, x, top + index * lineHeight + size * 0.95);
  });
  context.restore();

  return lines.length > visible ||
    lines.some((line) => context.measureText(line).width > width + 0.5)
    ? `${elementName(element)} passt nicht und wird abgeschnitten.`
    : null;
};

/**
 * Break a text into lines that fit the width; words longer than a line are broken anywhere
 * ("broken" tells, so shrinking can try a smaller size first).
 */
const wrap = (
  context: CanvasRenderingContext2D,
  text: string,
  width: number,
) => {
  const lines: string[] = [];
  let broken = false;
  for (const paragraph of text.split("\n")) {
    let line = "";
    for (const word of paragraph.split(/\s+/).filter(Boolean)) {
      const candidate = line ? `${line} ${word}` : word;
      if (context.measureText(candidate).width <= width || !line) {
        line = candidate;
      } else {
        lines.push(line);
        line = word;
      }
      // a single word wider than the line
      while (context.measureText(line).width > width && line.length > 1) {
        broken = true;
        let cut = line.length - 1;
        while (
          cut > 1 &&
          context.measureText(line.slice(0, cut)).width > width
        ) {
          cut--;
        }
        lines.push(line.slice(0, cut));
        line = line.slice(cut);
      }
    }
    lines.push(line);
  }
  return { lines, broken };
};

/**
 * Qr code or data matrix of the short link, square in the middle of the box. Modules are whole
 * pixels, so the code stays sharp on a thermal printer.
 */
const drawCode = (
  context: CanvasRenderingContext2D,
  element: LabelElement,
  data: LabelData,
  width: number,
  height: number,
) => {
  const matrix = matrixOf(element.symbology ?? "qrcode", data.url);
  const side = Math.min(width, height);
  const fitted = Math.floor(side / Math.max(matrix.columns, matrix.rows));
  const module =
    fitted >= 1 ? fitted : side / Math.max(matrix.columns, matrix.rows);
  const left = Math.round((width - module * matrix.columns) / 2);
  const top = Math.round((height - module * matrix.rows) / 2);

  for (let row = 0; row < matrix.rows; row++) {
    for (let column = 0; column < matrix.columns; column++) {
      if (matrix.bits[row * matrix.columns + column]) {
        context.fillRect(
          left + column * module,
          top + row * module,
          module,
          module,
        );
      }
    }
  }
};

/**
 * Image fitted into the box without cutting it.
 */
const drawImage = (
  context: CanvasRenderingContext2D,
  element: LabelElement,
  data: LabelData,
  width: number,
  height: number,
  options: DrawOptions,
) => {
  const url = imageUrlOf(element, data, options.logoUrl);
  const image = url ? options.images.get(url) : null;

  if (!image) {
    if (options.placeholders) {
      context.fillStyle = "#e5e7eb";
      context.fillRect(0, 0, width, height);
      context.fillStyle = "#000";
    }
    return;
  }

  const scale = Math.min(
    width / image.naturalWidth,
    height / image.naturalHeight,
  );
  const drawnWidth = image.naturalWidth * scale;
  const drawnHeight = image.naturalHeight * scale;
  context.drawImage(
    image,
    (width - drawnWidth) / 2,
    (height - drawnHeight) / 2,
    drawnWidth,
    drawnHeight,
  );
};

/**
 * Problems of a template that do not depend on drawing: elements over the edge, codes too small to
 * read, elements outside the print width of a narrower print head.
 */
export const templateWarnings = (
  template: { width_mm: number; height_mm: number; elements: LabelElement[] },
  data: LabelData,
  maxWidthMm: number | null,
) => {
  const warnings: string[] = [];
  const margin = 0.01;
  const printable =
    maxWidthMm && maxWidthMm < template.width_mm
      ? [
          (template.width_mm - maxWidthMm) / 2,
          (template.width_mm + maxWidthMm) / 2,
        ]
      : null;

  for (const element of template.elements) {
    const name = elementName(element);
    if (
      element.x < -margin ||
      element.y < -margin ||
      element.x + element.w > template.width_mm + margin ||
      element.y + element.h > template.height_mm + margin
    ) {
      warnings.push(`${name} ragt über den Rand des Etiketts.`);
    } else if (
      printable &&
      (element.x < printable[0] - margin ||
        element.x + element.w > printable[1] + margin)
    ) {
      warnings.push(
        `${name} liegt außerhalb der Druckbreite: Der Drucker druckt nur ${maxWidthMm} mm in der Mitte.`,
      );
    }

    if (element.type === "code") {
      const matrix = matrixOf(element.symbology ?? "qrcode", data.url);
      const module =
        Math.min(element.w, element.h) / Math.max(matrix.columns, matrix.rows);
      if (module < MIN_MODULE_MM) {
        warnings.push(
          `Der Code ist zu klein zum Lesen (Punkte ${module.toFixed(2).replace(".", ",")} mm, mindestens ${String(MIN_MODULE_MM).replace(".", ",")} mm). Größer ziehen oder Data Matrix wählen.`,
        );
      }
    }
  }

  return warnings;
};

/**
 * Hard black and white for thermal printers: every pixel darker than the middle becomes black.
 */
export const toMonochrome = (canvas: HTMLCanvasElement) => {
  const context = canvas.getContext("2d", { willReadFrequently: true })!;
  const image = context.getImageData(0, 0, canvas.width, canvas.height);
  const pixels = image.data;
  for (let index = 0; index < pixels.length; index += 4) {
    // luminance; transparent pixels count as white
    const alpha = pixels[index + 3] / 255;
    const luminance =
      (0.299 * pixels[index] +
        0.587 * pixels[index + 1] +
        0.114 * pixels[index + 2]) *
        alpha +
      255 * (1 - alpha);
    const value = luminance < 128 ? 0 : 255;
    pixels[index] = pixels[index + 1] = pixels[index + 2] = value;
    pixels[index + 3] = 255;
  }
  context.putImageData(image, 0, 0);
};
