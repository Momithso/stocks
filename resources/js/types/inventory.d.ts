export {};

declare global {
  /**
   * Place of an item: a location or a box (App\Models\Item::placeSummary).
   */
  interface Place {
    type: "location" | "container";
    id: number;
    code: string;
    name: string | null;
  }

  /**
   * Item in the contents tree of a box.
   */
  interface ContentsItem {
    id: number;
    number: string;
    name: string;
    quantity: number;
    unit: string | null;
    kind: "single" | "bulk";
    is_container: boolean;
    contents: ContentsItem[];
  }
}
