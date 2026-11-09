import { createApp, h, type DefineComponent } from "vue";
import "../css/app.css";
import { createInertiaApp } from "@inertiajs/vue3";
import { library } from "@fortawesome/fontawesome-svg-core";
import { FontAwesomeIcon } from "@fortawesome/vue-fontawesome";
import AppLayout from "./layouts/AppLayout.vue";
import AuthLayout from "./layouts/AuthLayout.vue";
import KioskLayout from "./layouts/KioskLayout.vue";
import coreIcons from "./icons";

// icons of the core and of all modules (modules/<Module>/resources/js/icons.ts)
const moduleIcons = import.meta.glob<{ default: typeof coreIcons }>(
  "../../modules/*/resources/js/icons.ts",
  { eager: true },
);
library.add(
  ...coreIcons,
  ...Object.values(moduleIcons).flatMap((icons) => icons.default),
);

// pages of the core and of the modules; module pages are named "<Module>::<Page>"
const pages = import.meta.glob<DefineComponent>("./pages/**/*.vue");
const modulePages = import.meta.glob<DefineComponent>(
  "../../modules/*/resources/js/pages/**/*.vue",
);

/**
 * Find the page component of a page name.
 */
const resolvePage = (name: string) => {
  const [module, page] = name.includes("::") ? name.split("::") : [null, name];
  const path = module
    ? `../../modules/${module}/resources/js/pages/${page}.vue`
    : `./pages/${page}.vue`;
  const loader = (module ? modulePages : pages)[path];

  if (!loader) {
    throw new Error(`Page not found: ${name}`);
  }

  return loader();
};

/**
 * Default layout by the name of the page. A page can set its own layout with defineOptions.
 */
const layoutOf = (name: string) => {
  if (name.startsWith("Auth/") || name === "Setup") {
    return AuthLayout;
  }
  if (name.startsWith("Kiosk/") || name.includes("::Kiosk")) {
    return KioskLayout;
  }
  return AppLayout;
};

createInertiaApp({
  title: (title) => (title ? `${title} · Stocks` : "Stocks"),
  resolve: async (name) => {
    const page = await resolvePage(name);
    page.default.layout ??= layoutOf(name);
    return page;
  },
  progress: {
    color: "rgb(var(--color-accent))",
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .component("FontAwesomeIcon", FontAwesomeIcon)
      .mount(el);
  },
});
