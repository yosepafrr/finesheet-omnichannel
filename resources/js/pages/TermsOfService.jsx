import {
    BulletList,
    PolicySection,
    PublicSiteLayout,
} from "../components/public/PublicSiteLayout";
import { PUBLIC_SITE } from "../publicSite";

const description =
    "Ketentuan penggunaan Finesheet untuk akun, integrasi marketplace, data operasional, serta batasan layanan.";

export default function TermsOfService() {
    return (
        <PublicSiteLayout
            title="Terms of Service"
            description={description}
            path="/terms-of-service"
            eyebrow="Ketentuan penggunaan"
        >
            <PolicySection title="1. Penerimaan ketentuan">
                <p>
                    Dengan membuat akun atau menggunakan Finesheet, Anda menyetujui Ketentuan Layanan ini dan
                    Kebijakan Privasi kami. Apabila Anda menggunakan layanan untuk suatu usaha, Anda menyatakan memiliki
                    wewenang untuk menghubungkan toko dan data usaha tersebut.
                </p>
            </PolicySection>

            <PolicySection title="2. Tentang layanan">
                <p>
                    Finesheet adalah platform operasional dan analisis e-commerce yang membantu pengguna mengelola
                    produk, stok, pesanan, logistik, retur, HPP, escrow, supplier, biaya iklan, dan estimasi profit.
                </p>
                <p>
                    Finesheet merupakan layanan independen. Nama dan merek Shopee, TikTok, TikTok Shop, serta layanan
                    pihak ketiga lainnya adalah milik masing-masing pemiliknya.
                </p>
            </PolicySection>

            <PolicySection title="3. Akun dan keamanan">
                <BulletList>
                    <li>Anda wajib memberikan informasi akun yang benar dan menjaga kerahasiaan kredensial.</li>
                    <li>Anda bertanggung jawab atas aktivitas yang dilakukan melalui akun Anda.</li>
                    <li>Segera hubungi kami apabila terdapat akses tanpa izin atau dugaan penyalahgunaan.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="4. Integrasi pihak ketiga">
                <p>
                    Anda memilih sendiri akun toko atau iklan yang dihubungkan dan memberikan izin melalui platform
                    terkait. Ketersediaan data dan fitur dapat dipengaruhi oleh izin, batas API, perubahan kebijakan,
                    atau gangguan pada layanan pihak ketiga.
                </p>
                <p>
                    Anda bertanggung jawab memastikan penggunaan akun marketplace dan iklan mematuhi ketentuan masing-masing platform.
                </p>
            </PolicySection>

            <PolicySection title="5. Penggunaan yang diperbolehkan">
                <p>Anda tidak boleh menggunakan Finesheet untuk:</p>
                <BulletList>
                    <li>Mengakses toko, akun iklan, atau data yang tidak Anda miliki atau tidak Anda kelola secara sah.</li>
                    <li>Mengganggu keamanan, ketersediaan, atau integritas layanan.</li>
                    <li>Mengirimkan kode berbahaya, melakukan scraping yang tidak diizinkan, atau mencoba melewati pembatasan akses.</li>
                    <li>Melakukan kegiatan yang melanggar hukum atau hak pihak lain.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="6. Estimasi keuangan">
                <p>
                    HPP, escrow, biaya platform, biaya iklan, tagihan supplier, dan profit yang ditampilkan bergantung
                    pada data pengguna serta data platform. Nilai tersebut bersifat alat bantu operasional dan dapat
                    berubah setelah sinkronisasi. Finesheet bukan pengganti nasihat akuntansi, pajak, atau hukum.
                </p>
            </PolicySection>

            <PolicySection title="7. Ketersediaan dan perubahan layanan">
                <p>
                    Kami berupaya menjaga layanan tetap tersedia, namun tidak menjamin layanan selalu bebas gangguan.
                    Fitur dapat diperbarui, dibatasi, atau dihentikan untuk keamanan, pemeliharaan, kepatuhan, maupun
                    perubahan API pihak ketiga.
                </p>
            </PolicySection>

            <PolicySection title="8. Penangguhan dan penghentian akun">
                <p>
                    Kami dapat membatasi akun yang melanggar ketentuan ini atau menimbulkan risiko keamanan. Anda dapat
                    berhenti menggunakan layanan, memutuskan integrasi, dan meminta penutupan akun melalui kontak kami.
                </p>
            </PolicySection>

            <PolicySection title="9. Tanggung jawab">
                <p>
                    Sejauh diizinkan oleh ketentuan yang berlaku, Finesheet tidak bertanggung jawab atas keputusan bisnis
                    yang dibuat hanya berdasarkan estimasi, kehilangan akibat gangguan platform pihak ketiga, atau akses
                    yang terjadi karena kelalaian pengguna menjaga kredensial.
                </p>
            </PolicySection>

            <PolicySection title="10. Hukum dan kontak">
                <p>
                    Ketentuan ini ditafsirkan berdasarkan ketentuan yang berlaku di {PUBLIC_SITE.country}. Pertanyaan
                    mengenai layanan dapat dikirim ke{" "}
                    <a className="font-semibold text-[#304674] underline" href={`mailto:${PUBLIC_SITE.email}`}>
                        {PUBLIC_SITE.email}
                    </a>.
                </p>
            </PolicySection>
        </PublicSiteLayout>
    );
}
