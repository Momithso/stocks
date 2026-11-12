import { onBeforeUnmount, onMounted, ref, watch, type Ref } from "vue";
import { router } from "@inertiajs/vue3";

/**
 * Remaining time of the kiosk session. The server decides (setting "kiosk.session_timeout"): every
 * kiosk request refreshes the activity, and every answer brings the remaining seconds
 * (surface.expires_in). In between the timer counts down; "extend" and, at most every 15 seconds,
 * any touch or key ask the server for more time. At zero the kiosk locks, so the browser never
 * locks later than the server.
 */
export default function useKioskTimer(
  expiresIn: Ref<number | null | undefined>,
) {
  const remaining = ref(expiresIn.value ?? 0);
  const failed = ref(false);
  let deadline = Date.now() + remaining.value * 1000;
  let ticker: ReturnType<typeof setInterval> | undefined;
  let lastExtend = Date.now();

  const restart = (seconds: number) => {
    deadline = Date.now() + seconds * 1000;
    remaining.value = seconds;
  };

  const tick = () => {
    if (!expiresIn.value) {
      return;
    }
    remaining.value = Math.max(0, Math.round((deadline - Date.now()) / 1000));
    if (remaining.value === 0) {
      clearInterval(ticker);
      router.post("/kiosk/logout");
    }
  };

  const xsrfToken = () =>
    decodeURIComponent(
      document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? "",
    );

  const extend = async () => {
    lastExtend = Date.now();
    try {
      const response = await fetch("/kiosk/extend", {
        method: "POST",
        headers: { Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
      });
      if (response.status === 401) {
        router.visit("/kiosk");
        return;
      }
      const data = await response.json();
      failed.value = false;
      restart(data.expires_in);
    } catch {
      // no connection: the time runs on and locks at zero
      failed.value = true;
    }
  };

  // touches and keys count as activity, at most one request every 15 seconds
  const onActivity = () => {
    if (expiresIn.value && Date.now() - lastExtend > 15000) {
      extend();
    }
  };

  // every answer of the server starts the time again, also when it brings the same seconds as
  // before (a watch on the value alone would miss that, and the browser would lock too early)
  const onAnswer = () => {
    restart(expiresIn.value ?? 0);
    lastExtend = Date.now();
  };
  watch(expiresIn, onAnswer);
  let stopListening: (() => void) | undefined;

  onMounted(() => {
    stopListening = router.on("success", onAnswer);
    ticker = setInterval(tick, 1000);
    window.addEventListener("pointerdown", onActivity);
    window.addEventListener("keydown", onActivity);
  });
  onBeforeUnmount(() => {
    stopListening?.();
    clearInterval(ticker);
    window.removeEventListener("pointerdown", onActivity);
    window.removeEventListener("keydown", onActivity);
  });

  return { remaining, failed, extend };
}
