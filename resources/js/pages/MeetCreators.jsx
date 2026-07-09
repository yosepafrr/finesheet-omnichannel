import { useState } from "react";
import AppLayout from "../../views/components/layouts/AppLayout";

const CREATORS = [
    {
        name: "Yosep Adriana Fauzi R",
        role: "Undergraduate Computer Science Student",
        image: "/images/yosep.jpg",
        bio: "Arsitek di balik logika sistem backend dan manajemen database. Mengubah kopi menjadi kode yang efisien.",
        stack: ["Laravel", "PostgreSQL", "Livewire"],
        social: {
            github: "https://github.com/yosepafrr",
            linkedin: "https://linkedin.com/in/",
            email: "yosep.adrianaa@gmail.com",
        },
    },
    {
        name: "Nama Creator 2",
        role: "UI/UX & Frontend Dev",
        image: "https://ui-avatars.com/api/?name=Creator+Two&background=1e2f50&color=fff&size=512",
        bio: "Pencipta antarmuka yang memanjakan mata. Fokus pada pengalaman pengguna yang mulus dan interaktif.",
        stack: ["Tailwind", "Alpine.js", "Figma"],
        social: {
            github: "https://github.com/",
            linkedin: "https://linkedin.com/in/",
            email: "creator2@example.com",
        },
    },
];

function CopyEmailButton({ email }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(email);
            } else {
                // Fallback for HTTP
                const textArea = document.createElement("textarea");
                textArea.value = email;
                textArea.style.position = "fixed";
                textArea.style.left = "-9999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                document.execCommand("copy");
                document.body.removeChild(textArea);
            }
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            alert("Gagal menyalin. Silakan copy manual: " + email);
        }
    };

    return (
        <div className="relative group/tooltip">
            <button
                onClick={handleCopy}
                className="flex items-center gap-2 px-4 py-2 rounded-full bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 font-bold text-sm hover:bg-orange-500 hover:text-white dark:hover:bg-orange-500 dark:hover:text-white transition-all duration-300 active:scale-95 border border-orange-100 dark:border-orange-500/20 hover:border-orange-500"
            >
                {copied ? (
                    <svg className="w-4 h-4 animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                    </svg>
                ) : (
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                )}
                <span>{copied ? "Tersalin!" : "Email Me"}</span>
            </button>

            {/* Tooltip */}
            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-3 opacity-0 group-hover/tooltip:opacity-100 transition-all duration-300 pointer-events-none transform group-hover/tooltip:translate-y-0 translate-y-2 z-50">
                <div className="bg-gray-900 text-white text-xs px-3 py-2 rounded-lg shadow-xl whitespace-nowrap relative">
                    <span>{copied ? "Berhasil disalin ke clipboard!" : email}</span>
                    <div className="absolute top-full left-1/2 -translate-x-1/2 -mt-[1px] border-4 border-transparent border-t-gray-900"></div>
                </div>
            </div>
        </div>
    );
}

export default function MeetCreators() {
    return (
        <AppLayout>
            <div className="min-h-screen bg-gray-50/50 dark:bg-slate-900 relative overflow-hidden transition-colors duration-300">
                {/* Animated Blobs */}
                <div className="absolute top-0 left-1/4 w-96 h-96 bg-blue-100 dark:bg-blue-900/30 rounded-full mix-blend-multiply dark:mix-blend-normal filter blur-3xl opacity-30 animate-blob"></div>
                <div className="absolute top-0 right-1/4 w-96 h-96 bg-purple-100 dark:bg-purple-900/30 rounded-full mix-blend-multiply dark:mix-blend-normal filter blur-3xl opacity-30 animate-blob animation-delay-2000"></div>

                <div className="max-w-6xl mx-auto px-6 py-16 relative z-10">

                    {/* Hero */}
                    <div className="text-center max-w-2xl mx-auto mb-20">
                        <span className="text-[#304674] dark:text-blue-400 font-bold tracking-wider uppercase text-xs bg-blue-50 dark:bg-blue-500/10 px-3 py-1 rounded-full mb-4 inline-block">The Masterminds</span>
                        <h1 className="text-4xl md:text-5xl font-extrabold text-gray-900 dark:text-white tracking-tight mb-6">
                            Bertemu dengan <span className="text-transparent bg-clip-text bg-gradient-to-r from-[#304674] to-purple-600 dark:from-blue-400 dark:to-purple-400">Kreator.</span>
                        </h1>
                        <p className="text-gray-500 dark:text-slate-400 text-lg leading-relaxed">
                            Di balik sistem yang efisien, ada pemikiran yang berdedikasi. Kami membangun platform ini untuk menyederhanakan bisnis Kamu.
                        </p>
                    </div>

                    {/* Creator Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8 lg:gap-12">
                        {CREATORS.map((creator, idx) => (
                            <div key={idx} className="group relative bg-white dark:bg-slate-800 rounded-[2rem] p-8 shadow-xl shadow-gray-100 dark:shadow-slate-900/50 border border-gray-100 dark:border-slate-700 hover:border-[#304674]/30 dark:hover:border-blue-500/30 hover:shadow-2xl hover:shadow-[#304674]/10 dark:hover:shadow-blue-900/20 transition-all duration-500 ease-out hover:-translate-y-2">

                                {/* Avatar */}
                                <div className="relative w-32 h-32 mx-auto mb-6">
                                    <div className="absolute inset-0 bg-gradient-to-tr from-[#304674] to-purple-500 rounded-full blur opacity-40 group-hover:opacity-60 transition-opacity duration-500"></div>
                                    <img
                                        src={creator.image}
                                        alt={creator.name}
                                        className="relative w-full h-full object-cover rounded-full border-4 border-white dark:border-slate-700 shadow-md group-hover:scale-105 transition-transform duration-500"
                                    />
                                    <div className="absolute -bottom-3 left-1/2 -translate-x-1/2 bg-white dark:bg-slate-800 px-4 py-1 rounded-full shadow-sm border border-gray-100 dark:border-slate-700 whitespace-nowrap">
                                        <span className="text-xs font-bold text-[#304674] dark:text-blue-400 tracking-wide">{creator.role}</span>
                                    </div>
                                </div>

                                {/* Info */}
                                <div className="text-center mt-8">
                                    <h3 className="text-2xl font-bold text-gray-800 dark:text-white mb-2">{creator.name}</h3>

                                    <div className="flex justify-center gap-2 mb-6">
                                        {creator.stack.map((tech, i) => (
                                            <span key={i} className="px-2 py-1 bg-gray-50 dark:bg-slate-700 text-[10px] text-gray-500 dark:text-slate-400 font-mono rounded border border-gray-200 dark:border-slate-600">
                                                {tech}
                                            </span>
                                        ))}
                                    </div>

                                    <p className="text-gray-500 dark:text-slate-400 leading-relaxed text-sm mb-8 px-4">
                                        "{creator.bio}"
                                    </p>

                                    {/* Social Links */}
                                    <div className="flex justify-center items-center gap-4">
                                        <a href={creator.social.github} target="_blank" rel="noopener noreferrer" className="p-3 rounded-full bg-gray-50 dark:bg-slate-700 text-gray-600 dark:text-slate-300 hover:bg-black hover:text-white dark:hover:bg-white dark:hover:text-black transition-all duration-300">
                                            <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" /></svg>
                                        </a>
                                        <a href={creator.social.linkedin} target="_blank" rel="noopener noreferrer" className="p-3 rounded-full bg-gray-50 dark:bg-slate-700 text-gray-600 dark:text-slate-300 hover:bg-[#0077b5] hover:text-white transition-all duration-300">
                                            <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z" /></svg>
                                        </a>
                                        <CopyEmailButton email={creator.social.email} />
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Mission Section */}
                    <div className="mt-24 bg-white dark:bg-slate-800 rounded-3xl p-8 md:p-12 border border-gray-100 dark:border-slate-700 shadow-sm relative overflow-hidden">
                        <div className="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-500 via-purple-500 to-pink-500"></div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-10 items-center">
                            <div>
                                <h2 className="text-2xl md:text-3xl font-bold text-gray-800 dark:text-white mb-4">Kenapa Sistem Ini Dibuat?</h2>
                                <p className="text-gray-500 dark:text-slate-400 leading-relaxed mb-4">
                                    Kami menyadari bahwa mengelola konveksi dan toko online secara bersamaan adalah tantangan besar. Data stok yang tidak sinkron dan laporan keuangan yang berantakan seringkali menghambat pertumbuhan bisnis.
                                </p>
                                <p className="text-gray-500 dark:text-slate-400 leading-relaxed">
                                    Sistem ini lahir dari kebutuhan akan transparansi dan efisiensi. Kami menggabungkan <span className="font-bold text-[#304674] dark:text-blue-400">teknologi modern</span> dengan alur kerja bisnis riil untuk menciptakan solusi yang tidak hanya fungsional, tetapi juga menyenangkan untuk digunakan.
                                </p>
                            </div>
                            <div className="bg-gray-50 dark:bg-slate-900/50 rounded-2xl p-6 border border-dashed border-gray-200 dark:border-slate-700">
                                <div className="flex items-center gap-4 mb-4">
                                    <div className="w-12 h-12 bg-blue-100 dark:bg-blue-500/10 rounded-lg flex items-center justify-center text-blue-600 dark:text-blue-400">
                                        <span className="material-symbols-rounded text-2xl">rocket_launch</span>
                                    </div>
                                    <div>
                                        <h4 className="font-bold text-gray-800 dark:text-white">Misi Kami</h4>
                                        <p className="text-xs text-gray-400 dark:text-slate-500">Target Pengembangan</p>
                                    </div>
                                </div>
                                <ul className="space-y-3">
                                    {["Membantu UMKM Go Digital", "Otomatisasi Laporan Keuangan", "Integrasi Marketplace Seamless"].map((item, i) => (
                                        <li key={i} className="flex items-center gap-3 text-sm text-gray-600 dark:text-slate-300">
                                            <span className="material-symbols-rounded text-green-500 text-lg">check_circle</span>
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>{`
                @keyframes blob {
                    0% { transform: translate(0px, 0px) scale(1); }
                    33% { transform: translate(30px, -50px) scale(1.1); }
                    66% { transform: translate(-20px, 20px) scale(0.9); }
                    100% { transform: translate(0px, 0px) scale(1); }
                }
                .animate-blob { animation: blob 7s infinite; }
                .animation-delay-2000 { animation-delay: 2s; }
            `}</style>
        </AppLayout>
    );
}
