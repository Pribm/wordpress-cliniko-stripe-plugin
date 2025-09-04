import { defineConfig } from "vite";
import { resolve } from "path";

export default defineConfig({
  root: "src/Widgets/ClinikoForm/assets/js",
  build: {
    outDir: "../dist", // relative to the `root` (js folder → back up 3 levels into assets/dist)
    emptyOutDir: true,
    rollupOptions: {
      input: {
        form: resolve(__dirname, "src/Widgets/ClinikoForm/assets/js/index.js"),
        stripe: resolve(__dirname, "src/Widgets/ClinikoForm/assets/js/payment/stripe.js"),
        // "save-on-exit": resolve(__dirname, "src/Widgets/ClinikoForm/assets/js/payment/save-on-exit.js"),
      },
      output: {
        entryFileNames: `[name].bundle.js`,
        chunkFileNames: `chunks/[name]-[hash].js`,
        format: "es",
      },
    },
  },
});
