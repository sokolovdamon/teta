import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "ТЕТА — онлайн-психология",
    short_name: "ТЕТА",
    description: "Психологи с подтверждённой квалификацией, запись и видеосессии",
    start_url: "/client",
    display: "standalone",
    background_color: "#f4f4f7",
    theme_color: "#4d427a",
    lang: "ru",
    icons: [
      { src: "/brand/icon-192.png", sizes: "192x192", type: "image/png" },
      { src: "/brand/icon-512.png", sizes: "512x512", type: "image/png" },
    ],
  };
}
