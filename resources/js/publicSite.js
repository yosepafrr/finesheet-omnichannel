export const PUBLIC_SITE = {
    name: "Finesheet",
    legalName: "Finesheet",
    operatorName: "Yosep Adriana Fauzi R",
    email: "developer@finesheet.id",
    country: "Indonesia",
    website: "https://finesheet.id",
    lastUpdated: "20 Oktober 2026",
    description:
        "Platform operasional dan analisis e-commerce untuk membantu seller mengelola toko, produk, pesanan, stok, HPP, escrow, supplier, dan profit dalam satu sistem.",
};

export const PUBLIC_ROUTES = [
    "/",
    "",
    "/login",
    "/register",
    "/meet-the-creators",
    "/privacy-policy",
    "/terms-of-service",
    "/tiktok-ads-integration",
];

export const PUBLIC_DOCUMENT_ROUTES = [
    "/privacy-policy",
    "/terms-of-service",
    "/tiktok-ads-integration",
];

export const PUBLIC_NAVIGATION = [
    { label: "Integrasi TikTok Ads", path: "/tiktok-ads-integration" },
    { label: "Privacy Policy", path: "/privacy-policy" },
    { label: "Terms of Service", path: "/terms-of-service" },
];

export const publicUrl = (path) => `${PUBLIC_SITE.website}${path === "/" ? "" : path}`;
