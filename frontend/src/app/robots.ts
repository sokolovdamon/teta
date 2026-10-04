import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/config";

/** X-12: cabinets, the admin panel, the video room, the BFF and booking steps are not for search engines. */
export default function robots(): MetadataRoute.Robots {
  const base = SITE_URL.replace(/\/$/, "");
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/client", "/pro", "/admin", "/hr", "/room", "/bff", "/auth", "/podbor/book", "/unsubscribe", "/maintenance"],
    },
    sitemap: `${base}/sitemap.xml`,
    host: base,
  };
}
