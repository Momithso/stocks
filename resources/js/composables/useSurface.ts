import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * The surface a page runs on (e.g. the kiosk), see App\Support\Surface. Pages that are reused on a
 * surface build their addresses with url(): on the kiosk "/inventory/move" becomes
 * "/kiosk/inventory/move", on the pc and the phone it stays as it is.
 */
export default () => {
  const page = usePage<SharedProps>();

  const surface = computed(() => page.props.surface ?? null);
  const kiosk = computed(() => surface.value?.name === "kiosk");

  const url = (path: string) =>
    surface.value && path.startsWith("/")
      ? `${surface.value.base}${path}`
      : path;

  return { surface, kiosk, url };
};
