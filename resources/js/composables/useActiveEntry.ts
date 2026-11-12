import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Which menu entry belongs to the current page: the entry whose address is the longest start of
 * the path. So "/loans/returns" marks "Rückgaben", not also "Buchungen" ("/loans").
 */
export default function useActiveEntry() {
  const page = usePage<SharedProps>();

  const activeHref = computed(() => {
    const path = page.url.split("?")[0];
    const hrefs = page.props.navigation
      .flatMap((section) => section.entries)
      .flatMap((entry) => [entry, ...(entry.children ?? [])])
      .map((entry) => entry.href)
      .filter((href): href is string => href !== null)
      .filter((href) => path === href || path.startsWith(`${href}/`));

    return hrefs.sort((a, b) => b.length - a.length)[0] ?? null;
  });

  return {
    isActive: (entry: NavigationEntry) =>
      entry.href !== null && entry.href === activeHref.value,
  };
}
