/** @type {import('tailwindcss').Config} */

const defaultTheme = require("tailwindcss/defaultTheme");

/**
 * Colour tokens are css variables (resources/css/app.css), defined once for light and once for
 * dark mode. Components only use these names, never fixed colours, so the look is changed in
 * one place. The accent colour comes from the settings of the instance.
 */
const token = (name) => `rgb(var(--color-${name}) / <alpha-value>)`;

module.exports = {
  content: [
    "./resources/**/*.{js,ts,vue,blade.php}",
    "./modules/*/resources/**/*.{js,ts,vue}",
  ],
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        page: token("page"),
        surface: token("surface"),
        "surface-muted": token("surface-muted"),
        line: token("line"),
        ink: token("ink"),
        "ink-muted": token("ink-muted"),
        accent: token("accent"),
        "accent-hover": token("accent-hover"),
      },
      fontFamily: {
        sans: ['"Inter Variable"', ...defaultTheme.fontFamily.sans],
      },
    },
  },
  plugins: [require("@tailwindcss/forms"), require("@tailwindcss/typography")],
};
