import { onBeforeUnmount, onMounted } from "vue";

/**
 * Hand scanner without a focused field: a scanner types the code like a keyboard and ends with
 * Enter. Keys are collected while no field has the focus; a pause of more than a second starts
 * over, so stray key presses do not mix with a scan.
 */
export default function useScannerInput(onCode: (code: string) => void) {
  let buffer = "";
  let last = 0;

  const onKey = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement | null;
    if (
      target?.closest("input, textarea, select, [contenteditable]") ||
      event.ctrlKey ||
      event.metaKey ||
      event.altKey
    ) {
      return;
    }
    const now = Date.now();
    if (now - last > 1000) {
      buffer = "";
    }
    last = now;

    if (event.key === "Enter") {
      if (buffer.trim().length >= 3) {
        event.preventDefault();
        onCode(buffer.trim());
      }
      buffer = "";
    } else if (event.key.length === 1) {
      buffer += event.key;
    }
  };

  onMounted(() => window.addEventListener("keydown", onKey));
  onBeforeUnmount(() => window.removeEventListener("keydown", onKey));
}
