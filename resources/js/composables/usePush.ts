import { onMounted, ref } from "vue";

export type PushState =
  // the browser has no web push (old browser, private window)
  | "unsupported"
  // iPhone or iPad in the browser tab: push only from the home screen
  | "ios-browser"
  // the person or the browser blocked notifications
  | "denied"
  | "off"
  | "on";

export type DeviceKind = "ios" | "android" | "desktop";

// Laravel sets the cookie XSRF-TOKEN; sent back as header it passes the csrf check
const xsrfToken = () =>
  decodeURIComponent(
    document.cookie
      .split("; ")
      .find((cookie) => cookie.startsWith("XSRF-TOKEN="))
      ?.slice("XSRF-TOKEN=".length) ?? "",
  );

/**
 * Kind of device, for the instructions (documentation/NOTIFICATIONS.md).
 */
export const deviceKind = (): DeviceKind => {
  const agent = navigator.userAgent;
  // iPads report themselves as Mac, but have a touch screen
  if (
    /iPhone|iPad|iPod/.test(agent) ||
    (agent.includes("Macintosh") && navigator.maxTouchPoints > 1)
  ) {
    return "ios";
  }
  return agent.includes("Android") ? "android" : "desktop";
};

/**
 * Key of the server as bytes, as the push manager wants it.
 */
const keyBytes = (base64: string) => {
  const padded = (base64 + "=".repeat((4 - (base64.length % 4)) % 4))
    .replace(/-/g, "+")
    .replace(/_/g, "/");
  return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
};

/**
 * Web push of this device for one scope: "/" for the account of a member, the link of a borrower
 * page for that page. The service worker is public/sw.js. Permission is only asked after a tap.
 *
 * @param scope address the worker controls
 * @param subscribeUrl POST: stores the subscription (json)
 * @param unsubscribeUrl DELETE: removes it by its endpoint (json)
 */
export default function (
  scope: string,
  subscribeUrl: string,
  unsubscribeUrl: string,
) {
  const state = ref<PushState>("off");
  const busy = ref(false);
  const error = ref<string | null>(null);
  const endpoint = ref<string | null>(null);

  const supported = () =>
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window;

  const standalone = () =>
    window.matchMedia("(display-mode: standalone)").matches ||
    window.matchMedia("(display-mode: fullscreen)").matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true;

  const subscription = async () => {
    const registration = await navigator.serviceWorker.getRegistration(scope);
    return (await registration?.pushManager.getSubscription()) ?? null;
  };

  const refresh = async () => {
    if (!supported()) {
      state.value =
        deviceKind() === "ios" && !standalone() ? "ios-browser" : "unsupported";
      return;
    }
    if (Notification.permission === "denied") {
      state.value = "denied";
      return;
    }
    const current = await subscription().catch(() => null);
    endpoint.value = current?.endpoint ?? null;
    state.value = current ? "on" : "off";
  };

  const send = (method: "POST" | "DELETE", url: string, body: unknown) =>
    fetch(url, {
      method,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": xsrfToken(),
      },
      body: JSON.stringify(body),
    });

  /**
   * Ask for the permission, subscribe and store the subscription on the server.
   */
  const enable = async (publicKey: string) => {
    busy.value = true;
    error.value = null;
    try {
      const registration = await navigator.serviceWorker.register("/sw.js", {
        scope,
      });
      if ((await Notification.requestPermission()) !== "granted") {
        await refresh();
        return false;
      }
      const ready = await navigator.serviceWorker.ready;
      const created = await (
        registration.active ? registration : ready
      ).pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyBytes(publicKey),
      });
      const response = await send("POST", subscribeUrl, {
        ...created.toJSON(),
        content_encoding: (
          PushManager as unknown as {
            supportedContentEncodings?: string[];
          }
        ).supportedContentEncodings?.includes("aes128gcm")
          ? "aes128gcm"
          : "aesgcm",
      });
      if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        error.value =
          body.errors?.push?.[0] ??
          body.message ??
          "Das Gerät konnte nicht eingetragen werden.";
        await created.unsubscribe();
      }
      await refresh();
      return response.ok;
    } catch (exception) {
      error.value = `Push ließ sich nicht einschalten: ${(exception as Error).message}`;
      await refresh();
      return false;
    } finally {
      busy.value = false;
    }
  };

  /**
   * Switch push off on this device and remove the subscription on the server.
   */
  const disable = async () => {
    busy.value = true;
    try {
      const current = await subscription();
      if (current) {
        await send("DELETE", unsubscribeUrl, { endpoint: current.endpoint });
        await current.unsubscribe();
      }
      await refresh();
    } finally {
      busy.value = false;
    }
  };

  onMounted(refresh);

  return { state, busy, error, endpoint, enable, disable, refresh };
}
