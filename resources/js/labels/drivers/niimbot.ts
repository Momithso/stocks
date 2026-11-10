import type { NiimbotAbstractClient } from "@mmote/niimbluelib";

/**
 * Niimbot printers directly from the browser with NiimBlueLib (Bluetooth or USB). Only Chrome and
 * Edge offer these interfaces (pc, Mac, Android); elsewhere the label is downloaded as image for the
 * Niimbot app. NiimBlueLib is loaded only when printing, it is big and unofficial (alpha).
 */

// one connection per browser tab; a bluetooth printer belongs to a device, not to an account
let client: NiimbotAbstractClient | null = null;

export type Connection = "bluetooth" | "serial";

/**
 * Ways of direct printing this browser offers.
 */
export const directConnections = (): Connection[] => [
  ...("bluetooth" in navigator ? (["bluetooth"] as const) : []),
  ...("serial" in navigator ? (["serial"] as const) : []),
];

export const isConnected = () => client?.isConnected() ?? false;

/**
 * Connect to a printer; the browser shows its own dialog to choose the device.
 */
export const connect = async (connection: Connection) => {
  const lib = await import("@mmote/niimbluelib");
  await disconnect();
  client =
    connection === "bluetooth"
      ? new lib.NiimbotBluetoothClient()
      : new lib.NiimbotSerialClient();
  const info = await client.connect();
  return info.deviceName ?? null;
};

export const disconnect = async () => {
  if (client?.isConnected()) {
    await client.disconnect();
  }
  client = null;
};

/**
 * Print labels one after the other. The canvases are already in the dots of the printer and black
 * and white (see prepareForNiimbot). Stops before the next label when the signal is aborted.
 */
export const printDirect = async (
  canvases: HTMLCanvasElement[],
  copies: number,
  printer: PrinterData,
  onProgress: (done: number, total: number) => void,
  signal: AbortSignal,
) => {
  if (!client?.isConnected()) {
    throw new Error("Der Drucker ist nicht verbunden.");
  }
  const lib = await import("@mmote/niimbluelib");
  const task = client.protocol.newPrintTask(
    (client.getPrintTaskType() ??
      printer.model?.print_task ??
      "B1") as Parameters<typeof client.protocol.newPrintTask>[0],
    {
      totalPages: canvases.length * copies,
      density: printer.settings.density ?? 3,
      labelType: lib.LabelType.WithGaps,
    },
  );
  const direction = client.getModelMetadata()?.printDirection ?? "top";

  try {
    await task.printInit();
    for (const [index, canvas] of canvases.entries()) {
      if (signal.aborted) {
        break;
      }
      await task.printPage(
        lib.ImageEncoder.encodeCanvas(
          canvas,
          lib.PageColorType.SingleColor,
          direction,
        ),
        copies,
      );
      await task.waitForPageFinished();
      onProgress(index + 1, canvases.length);
    }
    await task.waitForFinished();
  } finally {
    await task.printEnd();
  }
};
