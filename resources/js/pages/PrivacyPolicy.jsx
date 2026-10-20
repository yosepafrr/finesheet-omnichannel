import {
    BulletList,
    PolicySection,
    PublicSiteLayout,
} from "../components/public/PublicSiteLayout";
import { PUBLIC_SITE } from "../publicSite";

const description =
    "Kebijakan ini menjelaskan data yang diproses Finesheet, tujuan penggunaannya, pilihan pengguna, dan cara menghubungi pengelola.";

export default function PrivacyPolicy() {
    return (
        <PublicSiteLayout
            title="Privacy Policy"
            description={description}
            path="/privacy-policy"
            eyebrow="Perlindungan data"
        >
            <PolicySection title="1. Tentang kebijakan ini">
                <p>
                    Kebijakan Privasi ini berlaku ketika Anda menggunakan situs, aplikasi, dan integrasi
                    Finesheet. Finesheet dikelola oleh {PUBLIC_SITE.operatorName} dan beroperasi dari {PUBLIC_SITE.country}.
                </p>
            </PolicySection>

            <PolicySection title="2. Data yang kami proses">
                <BulletList>
                    <li>Data akun, seperti nama, alamat email, kata sandi yang disimpan dalam bentuk hash, dan preferensi akun.</li>
                    <li>Data toko yang Anda hubungkan, termasuk identitas toko, platform, status otorisasi, dan token akses yang diperlukan untuk integrasi.</li>
                    <li>Data operasional e-commerce, seperti produk, SKU, stok, pesanan, retur, informasi logistik, escrow, biaya platform, HPP, dan data supplier.</li>
                    <li>Data pelanggan marketplace yang tersedia melalui izin platform, seperti nama penerima, kontak tersamarkan atau tersedia, dan alamat pengiriman.</li>
                    <li>Data iklan ketika Anda menghubungkan akun iklan, termasuk advertiser ID, akun yang dipilih, cakupan izin, biaya iklan, dan metrik performa yang diizinkan.</li>
                    <li>Data teknis dasar, seperti alamat IP, waktu akses, log aplikasi, perangkat, dan informasi browser untuk keamanan serta pemecahan masalah.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="3. Cara kami menggunakan data">
                <BulletList>
                    <li>Menyediakan sinkronisasi produk, pesanan, stok, logistik, retur, dan data keuangan.</li>
                    <li>Menghitung dan menampilkan estimasi HPP, escrow, biaya iklan, tagihan supplier, serta profit.</li>
                    <li>Menjaga keamanan akun, mencegah penyalahgunaan, dan menyelesaikan masalah teknis.</li>
                    <li>Mengirim pemberitahuan layanan yang berkaitan dengan akun atau integrasi Anda.</li>
                    <li>Memenuhi kewajiban hukum dan permintaan resmi yang berlaku.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="4. Otorisasi marketplace dan platform iklan">
                <p>
                    Koneksi ke Shopee, TikTok Shop, dan TikTok Ads dilakukan melalui alur otorisasi resmi platform.
                    Finesheet hanya meminta dan menggunakan akses yang diperlukan untuk fitur yang Anda aktifkan.
                    Anda dapat mencabut akses melalui platform terkait atau menghubungi kami.
                </p>
                <p>
                    Detail penggunaan TikTok Ads tersedia di halaman <a className="font-semibold text-[#304674] underline" href="/tiktok-ads-integration">Integrasi TikTok Ads</a>.
                </p>
            </PolicySection>

            <PolicySection title="5. Pembagian data">
                <p>Kami tidak menjual data pribadi Anda. Data dapat diproses atau dibagikan secara terbatas kepada:</p>
                <BulletList>
                    <li>Platform marketplace atau iklan yang Anda hubungkan, sesuai tindakan dan izin Anda.</li>
                    <li>Penyedia infrastruktur, basis data, email, dan layanan teknis yang membantu menjalankan Finesheet.</li>
                    <li>Otoritas yang berwenang apabila diwajibkan oleh hukum.</li>
                </BulletList>
            </PolicySection>

            <PolicySection title="6. Keamanan dan penyimpanan">
                <p>
                    Kami menggunakan HTTPS, kontrol akses, pemisahan data berdasarkan pengguna, pencatatan aktivitas,
                    dan perlindungan kredensial. Token TikTok Ads disimpan terenkripsi. Tidak ada sistem yang sepenuhnya
                    bebas risiko, sehingga kami terus mengevaluasi pengamanan layanan.
                </p>
                <p>
                    Data disimpan selama akun atau integrasi masih aktif dan selama diperlukan untuk menyediakan layanan,
                    menyelesaikan sengketa, menjaga catatan transaksi, serta memenuhi kewajiban yang berlaku.
                </p>
            </PolicySection>

            <PolicySection title="7. Hak dan pilihan pengguna">
                <p>
                    Anda dapat meminta akses, koreksi, ekspor, atau penghapusan data yang berada dalam kendali Finesheet.
                    Anda juga dapat memutuskan koneksi toko atau mencabut otorisasi platform. Sebagian data mungkin tetap
                    disimpan apabila diperlukan untuk keamanan, pencatatan transaksi, atau kewajiban hukum.
                </p>
            </PolicySection>

            <PolicySection title="8. Perubahan kebijakan">
                <p>
                    Kebijakan ini dapat diperbarui ketika layanan atau kewajiban kami berubah. Tanggal pembaruan akan
                    ditampilkan pada bagian atas halaman ini.
                </p>
            </PolicySection>

            <PolicySection title="9. Kontak">
                <p>
                    Pertanyaan dan permintaan terkait privasi dapat dikirim ke{" "}
                    <a className="font-semibold text-[#304674] underline" href={`mailto:${PUBLIC_SITE.email}`}>
                        {PUBLIC_SITE.email}
                    </a>.
                </p>
            </PolicySection>
        </PublicSiteLayout>
    );
}
