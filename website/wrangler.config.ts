// Build settings for `cf deploy`, which builds this assets-only project with Wrangler.
import { defineWranglerConfig } from "wrangler/experimental-config";

export default defineWranglerConfig({
  assetsDirectory: "public",
});
