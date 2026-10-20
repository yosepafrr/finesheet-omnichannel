import { useEffect } from "react";
import {
    ArrowLeft,
    BarChart3,
    ExternalLink,
    Mail,
    MapPin,
    UserRound,
} from "lucide-react";
import {
    PUBLIC_NAVIGATION,
    PUBLIC_SITE,
    publicUrl,
} from "../../publicSite";

function setMetaContent(selector, attribute, value) {
    let element = document.head.querySelector(selector);

    if (!element) {
        element = document.createElement("meta");
        const [key, keyValue] = attribute;
        element.setAttribute(key, keyValue);
        document.head.appendChild(element);
    }

    element.setAttribute("content", value);
}

export function usePublicPageMetadata({ title, description, path }) {
    useEffect(() => {
        document.title = `${title} | ${PUBLIC_SITE.name}`;
        setMetaContent('meta[name="description"]', ["name", "description"], description);
        setMetaContent('meta[property="og:title"]', ["property", "og:title"], `${title} | ${PUBLIC_SITE.name}`);
        setMetaContent('meta[property="og:description"]', ["property", "og:description"], description);
        setMetaContent('meta[property="og:url"]', ["property", "og:url"], publicUrl(path));

        let canonical = document.head.querySelector('link[rel="canonical"]');
        if (!canonical) {
            canonical = document.createElement("link");
            canonical.setAttribute("rel", "canonical");
            document.head.appendChild(canonical);
        }
        canonical.setAttribute("href", publicUrl(path));
    }, [description, path, title]);
}

function Brand() {
    return (
        <a
            href="/"
            className="flex items-center gap-2 text-slate-950 transition hover:text-[#304674]"
            aria-label="Kembali ke halaman utama Finesheet"
        >
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[#304674] text-white">
                <BarChart3 className="h-5 w-5" aria-hidden="true" />
            </span>
            <span className="text-lg font-extrabold">Finesheet</span>
        </a>
    );
}

export function PublicSiteLayout({
    title,
    description,
    path,
    eyebrow = "Informasi publik",
    children,
}) {
    usePublicPageMetadata({ title, description, path });

    return (
        <div className="min-h-[100dvh] bg-[#f7f8fb] font-sans text-slate-900">
            <header className="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <Brand />

                    <nav className="hidden items-center gap-6 md:flex" aria-label="Navigasi halaman publik">
                        {PUBLIC_NAVIGATION.map((item) => (
                            <a
                                key={item.path}
                                href={item.path}
                                aria-current={path === item.path ? "page" : undefined}
                                className={`text-sm font-semibold transition ${
                                    path === item.path
                                        ? "text-[#304674]"
                                        : "text-slate-600 hover:text-slate-950"
                                }`}
                            >
                                {item.label}
                            </a>
                        ))}
                    </nav>

                    <a
                        href="#/login"
                        className="inline-flex h-10 items-center justify-center rounded-lg bg-[#304674] px-4 text-sm font-bold text-white transition hover:bg-[#263b66] active:translate-y-px"
                    >
                        Masuk
                    </a>
                </div>
            </header>

            <main>
                <section className="border-b border-slate-200 bg-white">
                    <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
                        <a
                            href="/"
                            className="mb-8 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 transition hover:text-[#304674]"
                        >
                            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                            Halaman utama
                        </a>
                        <p className="mb-3 text-sm font-bold text-[#304674]">{eyebrow}</p>
                        <h1 className="max-w-3xl text-3xl font-extrabold leading-tight text-slate-950 sm:text-4xl">
                            {title}
                        </h1>
                        <p className="mt-5 max-w-2xl text-base leading-7 text-slate-600">{description}</p>
                        <p className="mt-5 text-sm font-medium text-slate-500">
                            Terakhir diperbarui: {PUBLIC_SITE.lastUpdated}
                        </p>
                    </div>
                </section>

                <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">{children}</div>
            </main>

            <footer className="border-t border-slate-200 bg-white">
                <div className="mx-auto grid max-w-6xl gap-10 px-4 py-10 sm:px-6 md:grid-cols-[1.2fr_1fr_1fr] lg:px-8">
                    <div>
                        <Brand />
                        <p className="mt-4 max-w-md text-sm leading-6 text-slate-600">
                            {PUBLIC_SITE.description}
                        </p>
                    </div>

                    <div>
                        <h2 className="text-sm font-bold text-slate-950">Identitas pengelola</h2>
                        <dl className="mt-4 space-y-3 text-sm text-slate-600">
                            <div className="flex gap-2">
                                <UserRound className="mt-0.5 h-4 w-4 shrink-0 text-[#304674]" aria-hidden="true" />
                                <div>
                                    <dt className="sr-only">Pengelola</dt>
                                    <dd>{PUBLIC_SITE.operatorName}</dd>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-[#304674]" aria-hidden="true" />
                                <div>
                                    <dt className="sr-only">Negara operasi</dt>
                                    <dd>{PUBLIC_SITE.country}</dd>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <Mail className="mt-0.5 h-4 w-4 shrink-0 text-[#304674]" aria-hidden="true" />
                                <div>
                                    <dt className="sr-only">Email</dt>
                                    <dd>
                                        <a className="hover:text-[#304674] hover:underline" href={`mailto:${PUBLIC_SITE.email}`}>
                                            {PUBLIC_SITE.email}
                                        </a>
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    </div>

                    <div>
                        <h2 className="text-sm font-bold text-slate-950">Dokumen publik</h2>
                        <nav className="mt-4 flex flex-col items-start gap-3" aria-label="Dokumen publik">
                            {PUBLIC_NAVIGATION.map((item) => (
                                <a
                                    key={item.path}
                                    href={item.path}
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-[#304674]"
                                >
                                    {item.label}
                                    <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            ))}
                        </nav>
                    </div>
                </div>
                <div className="border-t border-slate-200 px-4 py-5 text-center text-xs font-medium text-slate-500">
                    &copy; {new Date().getFullYear()} {PUBLIC_SITE.legalName}. Dikelola di {PUBLIC_SITE.country}.
                </div>
            </footer>
        </div>
    );
}

export function PolicySection({ title, children }) {
    return (
        <section className="border-t border-slate-200 py-8 first:border-t-0 first:pt-0">
            <h2 className="text-xl font-bold text-slate-950">{title}</h2>
            <div className="mt-4 space-y-4 text-[15px] leading-7 text-slate-700">{children}</div>
        </section>
    );
}

export function BulletList({ children }) {
    return <ul className="list-disc space-y-2 pl-5 marker:text-[#304674]">{children}</ul>;
}
