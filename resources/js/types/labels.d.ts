export {};

declare global {
  /**
   * Element of a label template, positions and sizes in mm (App\Support\Labels\LabelElements).
   */
  interface LabelElement {
    id: string;
    type: "text" | "code" | "logo" | "image" | "photo" | "line" | "rect";
    x: number;
    y: number;
    w: number;
    h: number;
    rotation: 0 | 90 | 180 | 270;
    // text
    text?: string;
    font_size?: number;
    bold?: boolean;
    align?: "left" | "center" | "right";
    valign?: "top" | "middle" | "bottom";
    overflow?: "shrink" | "clip";
    // code
    symbology?: "qrcode" | "datamatrix";
    // free image on S3
    path?: string;
    // line and frame, in mm
    stroke?: number;
  }

  type LabelTargetName = "item" | "box" | "location" | "reserve";

  interface LabelTemplate {
    id: number;
    name: string;
    target: LabelTargetName;
    target_label: string;
    width_mm: number;
    height_mm: number;
    elements: LabelElement[];
    is_default: boolean;
  }

  /**
   * Data of one label from App\Support\Labels\LabelData.
   */
  interface LabelData {
    key: string;
    target: LabelTargetName;
    id: number;
    title: string;
    code: string;
    url: string;
    photo_url: string | null;
    printed_at: string | null;
    values: Record<string, string>;
  }

  interface PrinterModelData {
    id: number;
    name: string;
    dpi: number;
    max_width_mm: number;
    print_task: string;
    label_sizes: { width: number; height: number }[];
  }

  interface PrinterData {
    id: number;
    name: string;
    driver: "paper" | "niimbot";
    driver_label: string;
    printer_model_id: number | null;
    model: PrinterModelData | null;
    settings: {
      // paper
      page?: "single" | "sheet";
      columns?: number;
      rows?: number;
      margin_top?: number;
      margin_left?: number;
      gap_x?: number;
      gap_y?: number;
      // niimbot
      density?: number;
      offset_x?: number;
      offset_y?: number;
      rotation?: 0 | 90 | 180 | 270;
    };
  }
}
