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
   * Notice of a module about an item (App\Support\Extensions\ItemInfoProvider), e.g.
   * "ausgegeben an … bis …". A lock stops moving, splitting and retiring.
   */
  interface ItemNotice {
    text: string;
    badge: string | null;
    variant: "info" | "warning" | "danger";
    href: string | null;
    lock: boolean;
    actions: { title: string; href: string; icon: string }[];
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
