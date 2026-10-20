import {
    BarChart3,
    CheckCircle2,
    KeyRound,
    Link2,
    ShieldCheck,
} from "lucide-react";
import {
    BulletList,
    PolicySection,
    PublicSiteLayout,
} from "../components/public/PublicSiteLayout";
import { PUBLIC_SITE } from "../publicSite";

const description =
    "Penjelasan publik tentang tujuan, alur otorisasi, data, dan batas penggunaan integrasi TikTok Ads di Finesheet.";

const steps = [
    {
        title: "Pengguna memulai koneksi",
        description: "Pengguna memilih Hubungkan TikTok Ads dari akun Finesheet miliknya.",
        icon: Link2,
    },
    {
        title: "Otorisasi dilakukan di TikTok",
        description: "Pengguna dialihkan ke halaman resmi TikTok untuk memilih advertiser account dan menyetujui akses.",
        icon: KeyRound,
    },
    {
        title: "Data dipetakan ke toko",
        description: "Advertiser account yang disetujui dipetakan ke toko TikTok Shop milik pengguna.",
        icon: ShieldCheck,
    },
    {
        title: "Biaya iklan masuk ke analisis",
        description: "Data reporting yang diizinkan digunakan untuk mengurangi profit sesuai toko dan tanggal.",
        icon: BarChart3,
    },
];

export default function TiktokAdsIntegration() {
    return (
        <PublicSiteLayout
            title="Integrasi TikTok Ads"
            description={description}
            path="/tiktok-ads-integration"
            eyebrow="Transparansi integrasi"
        >
            <section className="mb-12 border-l-4 border-[#304674] bg-white px-5 py-5 shadow-sm">
                <div className="flex items-start gap-3">
                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-[#304674]" aria-hidden="true" />
                    <div>
                        <h2 className="font-bold text-slate-950">Tujuan integrasi</h2>
                        <p className="mt-2 text-[15px] leading-7 text-slate-700">
                            Finesheet menggunakan TikTok Business API untuk membantu seller yang memberikan otorisasi
                            melihat biaya iklan bersama HPP, escrow, dan profit. Integrasi ini bersifat analitik dan tidak
                            digunakan untuk membuat, mengubah, menerbitkan, atau menghapus kampanye iklan.
                        </p>
                    </div>
                </div>
            </section>

            <PolicySection title="Cara integrasi bekerja">
                <div className="grid gap-3 sm:grid-cols-2">
                    {steps.map((step, index) => {
                        const Icon = step.icon;
                        return (
                            <article key={step.title} className="rounded-lg border border-slate-200 bg-white p-5">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[#eef2fa] text-[#304674]">
                                        <Icon className="h-4.5 w-4.5" aria-hidden="true" />
                                    </span>
                                    <span className="text-xs font-bold text-slate-500">Langkah {index + 1}</span>
                                </div>
                                <h3 className="mt-4 font-bold text-slate-950">{step.title}</h3>
                                <p className="mt-2 text-sm leading-6 text-slate-600">{step.description}</p>
                            </article>
                        );
                    })}
                </div>
            </PolicySection>

            <PolicySection title="Data yang diproses">
                <p>Setelah pengguna memberikan izin, Finesheet dapat memproses data berikut sesuai cakupan akses yang disetujui:</p>
                <BulletList>
                    <li>Advertiser ID dan identitas dasar akun iklan.</li>
                    <li>Nama akun, mata uang, zona waktu, serta pemetaan akun iklan ke toko pengguna.</li>
                    <li>Identitas kampanye dan data pelaporan seperti tanggal, biaya iklan, serta metrik performa yang tersedia.</li>
                    <li>Metadata otorisasi, cakupan izin, dan waktu sinkronisasi untuk menjaga koneksi.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="Cara data digunakan">
                <BulletList>
                    <li>Menyajikan total pengeluaran iklan per hari dan per toko.</li>
                    <li>Menampilkan rincian performa pada tingkat kampanye ketika data tersedia.</li>
                    <li>Menggabungkan biaya iklan dengan data HPP dan escrow untuk menghasilkan analisis profit.</li>
                    <li>Mendiagnosis kegagalan sinkronisasi dan menjaga integrasi tetap berjalan.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="Batas penggunaan">
                <BulletList>
                    <li>Finesheet tidak meminta kata sandi TikTok pengguna.</li>
                    <li>Finesheet tidak membuat atau mengubah kampanye, ad group, materi iklan, anggaran, maupun bid.</li>
                    <li>Data tidak dijual atau digunakan untuk menargetkan iklan milik pihak lain.</li>
                    <li>Setiap pengguna hanya dapat melihat advertiser account yang telah diotorisasi untuk akunnya.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="Keamanan dan pencabutan akses">
                <p>
                    OAuth state sekali pakai digunakan untuk melindungi proses koneksi. Access token TikTok Ads disimpan
                    terenkripsi dan tidak ditampilkan kepada pengguna lain. Pengguna dapat mencabut izin melalui pengaturan
                    TikTok atau meminta pemutusan koneksi melalui {" "}
                    <a className="font-semibold text-[#304674] underline" href={`mailto:${PUBLIC_SITE.email}`}>
                        {PUBLIC_SITE.email}
                    </a>.
                </p>
            </PolicySection>

            <PolicySection title="Pengelola integrasi">
                <dl className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 sm:grid-cols-2">
                    <div>
                        <dt className="text-xs font-bold text-slate-500">Produk</dt>
                        <dd className="mt-1 font-semibold text-slate-950">{PUBLIC_SITE.name}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-bold text-slate-500">Pengelola</dt>
                        <dd className="mt-1 font-semibold text-slate-950">{PUBLIC_SITE.operatorName}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-bold text-slate-500">Negara operasi</dt>
                        <dd className="mt-1 font-semibold text-slate-950">{PUBLIC_SITE.country}</dd>
                    </div>
                    <div>
                        <dt className="text-xs font-bold text-slate-500">Kontak teknis</dt>
                        <dd className="mt-1 font-semibold text-slate-950">{PUBLIC_SITE.email}</dd>
                    </div>
                </dl>
            </PolicySection>
        </PublicSiteLayout>
    );
}
