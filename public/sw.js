/*
 * Service worker of Stocks, only for push (documentation/NOTIFICATIONS.md). No cache, no offline
 * mode. Stocks registers it with the scope "/" for members, the borrower page with its own link as
 * scope. Every push shows a notification at once (Safari requires that); a tap opens its address.
 */

self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (event) => event.waitUntil(self.clients.claim()));

self.addEventListener("push", (event) => {
  let message = {};
  try {
    message = event.data ? event.data.json() : {};
  } catch (error) {
    message = { title: "Stocks", body: event.data ? event.data.text() : "" };
  }

  event.waitUntil(
    self.registration.showNotification(message.title || "Stocks", {
      body: message.body || "",
      icon: message.icon || "/web-app/icon-192.png",
      badge: "/web-app/icon-192.png",
      tag: message.tag,
      data: message.data || {},
    }),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = new URL(
    (event.notification.data && event.notification.data.url) ||
      self.registration.scope,
    self.location.origin,
  ).href;

  event.waitUntil(
    self.clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then((windows) => {
        // an open window of the same address comes to the front, else a new one opens
        const open = windows.find((client) => client.url === url);
        if (open) {
          return open.focus();
        }
        return self.clients.openWindow(url);
      }),
  );
});
