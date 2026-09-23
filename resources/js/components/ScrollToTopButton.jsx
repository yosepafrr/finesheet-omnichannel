import { AnimatePresence, motion } from "framer-motion";
import React, { useEffect, useState } from "react";
import { createPortal } from "react-dom";

export default function ScrollToTopButton({ markerId }) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const container = document.getElementById("main-scroll-container");
        const marker = document.getElementById(markerId);
        if (!container || !marker) return undefined;

        const updateVisibility = () => {
            if (!window.matchMedia("(max-width: 767px)").matches) {
                setVisible(false);
                return;
            }

            const containerTop = container.getBoundingClientRect().top;
            const markerBottom = marker.getBoundingClientRect().bottom;
            setVisible(container.scrollTop > 0 && markerBottom <= containerTop);
        };

        updateVisibility();
        container.addEventListener("scroll", updateVisibility, { passive: true });
        window.addEventListener("resize", updateVisibility);

        return () => {
            container.removeEventListener("scroll", updateVisibility);
            window.removeEventListener("resize", updateVisibility);
        };
    }, [markerId]);

    const scrollToTop = () => {
        document.getElementById("main-scroll-container")?.scrollTo({
            top: 0,
            behavior: "smooth",
        });
    };

    return createPortal(
        <AnimatePresence>
            {visible && (
                <motion.button
                    type="button"
                    initial={{ opacity: 0, y: 10, scale: 0.92 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: 10, scale: 0.92 }}
                    transition={{ duration: 0.18 }}
                    onClick={scrollToTop}
                    className="fixed bottom-20 right-4 z-[70] flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-[#304674] shadow-lg active:scale-95 dark:border-slate-700 dark:bg-slate-800 dark:text-blue-400 md:hidden"
                    aria-label="Kembali ke atas"
                    title="Kembali ke atas"
                >
                    <span className="material-symbols-rounded text-xl">arrow_upward</span>
                </motion.button>
            )}
        </AnimatePresence>,
        document.body,
    );
}
