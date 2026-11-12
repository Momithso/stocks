import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import Components from "unplugin-vue-components/vite";
import AutoImport from "unplugin-auto-import/vite";
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync } from "node:fs";
import { networkInterfaces } from "node:os";

/**
 * Self-signed certificate for the https mode of development (DEV_HTTPS=true). Made once with
 * openssl in storage/dev-https; DEV_HTTPS_CERT and DEV_HTTPS_KEY can name other files (e.g. made
 * by mkcert, which devices trust without a warning).
 */
const devCertificate = (env) => {
  if (env.DEV_HTTPS_CERT && env.DEV_HTTPS_KEY) {
    return {
      cert: readFileSync(env.DEV_HTTPS_CERT),
      key: readFileSync(env.DEV_HTTPS_KEY),
    };
  }

  const dir = "storage/dev-https";
  if (!existsSync(`${dir}/cert.pem`)) {
    mkdirSync(dir, { recursive: true });
    // valid for localhost and every IPv4 address of this computer
    const addresses = Object.values(networkInterfaces())
      .flat()
      .filter((entry) => entry?.family === "IPv4")
      .map((entry) => `IP:${entry.address}`);
    execFileSync("openssl", [
      "req",
      "-x509",
      "-newkey",
      "rsa:2048",
      "-nodes",
      "-days",
      "825",
      "-subj",
      "/CN=Stocks dev",
      "-addext",
      `subjectAltName=DNS:localhost,${addresses.join(",")}`,
      "-keyout",
      `${dir}/key.pem`,
      "-out",
      `${dir}/cert.pem`,
    ]);
  }

  return {
    cert: readFileSync(`${dir}/cert.pem`),
    key: readFileSync(`${dir}/key.pem`),
  };
};

export default defineConfig(({ mode }) => {
  // the dev server is reached under the host of APP_URL, so other devices in the network can load the assets
  const env = loadEnv(mode, process.cwd(), "");
  const appUrl = env.APP_URL;
  const hmrHost = URL.canParse(appUrl) ? new URL(appUrl).hostname : "localhost";

  // https mode of development: the dev server answers with https and passes everything that is not
  // an asset on to Laravel (php artisan serve), so phones can use camera and bluetooth under one address
  const devHttps = env.DEV_HTTPS === "true";

  return {
    build: {
      target: "esnext",
    },
    optimizeDeps: {
      esbuildOptions: {
        target: "esnext",
      },
    },
    resolve: {
      alias: {
        "@": "/resources/js",
      },
    },
    server: {
      host: "0.0.0.0",
      hmr: {
        host: hmrHost,
      },
      ...(devHttps
        ? {
            https: devCertificate(env),
            proxy: {
              "^/(?!@|resources/|modules/|node_modules/)": {
                target: env.DEV_HTTPS_TARGET || "http://127.0.0.1:8000",
                xfwd: true,
              },
            },
          }
        : {}),
    },
    plugins: [
      laravel({
        input: ["resources/js/app.ts"],
        refresh: true,
      }),
      vue({
        template: {
          transformAssetUrls: {
            base: null,
            includeAbsolute: false,
          },
        },
      }),
      AutoImport({
        dirs: ["./resources/js/composables"],
        dts: "./resources/js/types/auto-imports.d.ts",
        directoryAsNamespace: true,
      }),
      Components({
        dirs: ["./resources/js/components", "./resources/js/layouts"],
        dts: "./resources/js/types/components.d.ts",
        directoryAsNamespace: true,
      }),
    ],
    define: {
      __PACKAGE_NAME__: JSON.stringify(process.env.npm_package_name),
      __PACKAGE_REPOSITORY_URL__: JSON.stringify(
        "https://github.com/fsr5-fhaachen/stocks",
      ),
    },
  };
});
