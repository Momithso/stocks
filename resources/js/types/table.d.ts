export {};

declare global {
  /**
   * Column of components/ui/Table.vue. The cell shows value(row) as text, or the slot
   * "cell-<name>" of the table for anything else (links, badges, buttons).
   */
  interface TableColumn {
    name: string;
    header: string;
    value?: (row: any) => string | number | null | undefined;
    // sort key of the table class on the server (app/Support/Table); without it the column can not be sorted
    sortKey?: string;
  }
}
