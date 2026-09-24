import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { motion, AnimatePresence } from "framer-motion";
import { X, ChevronLeft, ChevronRight, HelpCircle } from "lucide-react";

/**
 * OnboardingTour — spotlight-style guided tour component.
 *
 * Props:
 *  - steps: Array<{ selector: string, title: string, description: string, position?: "top"|"bottom"|"left"|"right" }>
 *  - isOpen: boolean
 *  - currentStep: number
 *  - onNext: () => void
 *  - onPrev: () => void
 *  - onSkip: () => void
 *  - onFinish: () => void
 *  - onStart: () => void  (for the floating help button)
 */
export default function OnboardingTour({
    steps,
    isOpen,
    currentStep,
    onNext,
    onPrev,
    onSkip,
    onFinish,
    onStart,
}) {
    const [targetRect, setTargetRect] = useState(null);
    const [tooltipPos, setTooltipPos] = useState({ top: 0, left: 0 });
    const tooltipRef = useRef(null);

    const step = steps[currentStep];
    const isLast = currentStep === steps.length - 1;
    const isFirst = currentStep === 0;

    // Calculate position of the highlighted element
    useEffect(() => {
        if (!isOpen || !step) return;

        const measure = () => {
            const el = step.selector ? document.querySelector(step.selector) : null;
            if (el) {
                const rect = el.getBoundingClientRect();
                setTargetRect({
                    top: rect.top,
                    left: rect.left,
                    width: rect.width,
                    height: rect.height,
                });
            } else {
                // No selector or element not found — center on screen
                setTargetRect(null);
            }
        };

        const revealTarget = () => {
            const el = step.selector ? document.querySelector(step.selector) : null;
            if (el) {
                el.scrollIntoView({
                    behavior: window.innerWidth < 768 ? "auto" : "smooth",
                    block: "center",
                });
            }
            measure();
        };

        const initialTimer = setTimeout(revealTarget, 120);
        const settledTimer = setTimeout(measure, 420);
        window.addEventListener("resize", measure);
        window.addEventListener("scroll", measure, true);

        return () => {
            clearTimeout(initialTimer);
            clearTimeout(settledTimer);
            window.removeEventListener("resize", measure);
            window.removeEventListener("scroll", measure, true);
        };
    }, [isOpen, currentStep, step]);

    // Position tooltip relative to target rect
    useEffect(() => {
        if (!targetRect || !tooltipRef.current) {
            // Center of viewport if no target
            setTooltipPos({
                top: Math.max(16, window.innerHeight / 2 - 100),
                left: Math.max(16, window.innerWidth / 2 - Math.min(320, window.innerWidth - 32) / 2),
            });
            return;
        }

        const TOOLTIP_WIDTH = Math.min(320, window.innerWidth - 32);
        const TOOLTIP_HEIGHT = tooltipRef.current?.offsetHeight || 160;
        const PADDING = 16;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const pos = step?.position || "bottom";

        let top, left;

        if (pos === "bottom") {
            top = targetRect.top + targetRect.height + PADDING;
            left = targetRect.left + targetRect.width / 2 - TOOLTIP_WIDTH / 2;
        } else if (pos === "top") {
            top = targetRect.top - TOOLTIP_HEIGHT - PADDING;
            left = targetRect.left + targetRect.width / 2 - TOOLTIP_WIDTH / 2;
        } else if (pos === "right") {
            top = targetRect.top + targetRect.height / 2 - TOOLTIP_HEIGHT / 2;
            left = targetRect.left + targetRect.width + PADDING;
        } else {
            // left
            top = targetRect.top + targetRect.height / 2 - TOOLTIP_HEIGHT / 2;
            left = targetRect.left - TOOLTIP_WIDTH - PADDING;
        }

        // Clamp to viewport
        left = Math.max(PADDING, Math.min(left, vw - TOOLTIP_WIDTH - PADDING));
        top = Math.max(PADDING, Math.min(top, vh - TOOLTIP_HEIGHT - PADDING));

        setTooltipPos({ top, left });
    }, [targetRect, step]);

    if (typeof document === "undefined") return null;

    return createPortal(
        <>
            {/* ── Spotlight Overlay ── */}
            <AnimatePresence>
                {isOpen && (
                    <motion.div
                        key="overlay"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.25 }}
                        className="fixed inset-0 z-[9000] pointer-events-auto"
                        style={{ background: "rgba(10, 12, 30, 0.60)" }}
                        onClick={onSkip}
                    >
                        {/* Spotlight cutout via SVG clip-path */}
                        {targetRect && (
                            <svg
                                className="absolute inset-0 w-full h-full pointer-events-none"
                                style={{ position: "fixed", inset: 0, width: "100vw", height: "100vh" }}
                            >
                                <defs>
                                    <mask id="spotlight-mask">
                                        <rect width="100%" height="100%" fill="white" />
                                        <rect
                                            x={targetRect.left - 8}
                                            y={targetRect.top - 8}
                                            width={targetRect.width + 16}
                                            height={targetRect.height + 16}
                                            rx="12"
                                            fill="black"
                                        />
                                    </mask>
                                </defs>
                                <rect
                                    width="100%"
                                    height="100%"
                                    fill="rgba(10, 12, 30, 0.0)"
                                    mask="url(#spotlight-mask)"
                                />
                            </svg>
                        )}

                        {/* Highlight ring on target */}
                        {targetRect && (
                            <motion.div
                                key={`ring-${currentStep}`}
                                initial={{ opacity: 0, scale: 0.97 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0 }}
                                transition={{ duration: 0.2 }}
                                className="absolute pointer-events-none"
                                style={{
                                    position: "fixed",
                                    top: targetRect.top - 8,
                                    left: targetRect.left - 8,
                                    width: targetRect.width + 16,
                                    height: targetRect.height + 16,
                                    borderRadius: 12,
                                    border: "2px solid rgba(58,84,176,0.85)",
                                    boxShadow: "0 0 0 4px rgba(58,84,176,0.18), 0 0 32px rgba(58,84,176,0.25)",
                                }}
                            />
                        )}
                    </motion.div>
                )}
            </AnimatePresence>

            {/* ── Tooltip Card ── */}
            <AnimatePresence>
                {isOpen && step && (
                    <motion.div
                        ref={tooltipRef}
                        key={`tooltip-${currentStep}`}
                        initial={{ opacity: 0, y: 10, scale: 0.97 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 8, scale: 0.96 }}
                        transition={{ duration: 0.22, ease: [0.16, 1, 0.3, 1] }}
                        className="fixed z-[9100] pointer-events-auto"
                        style={{
                            top: tooltipPos.top,
                            left: tooltipPos.left,
                            width: "min(320px, calc(100vw - 32px))",
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div
                            className="rounded-2xl overflow-hidden"
                            style={{
                                background: "rgba(255,255,255,0.97)",
                                border: "1px solid rgba(255,255,255,0.9)",
                                boxShadow: "0 24px 60px rgba(10,12,30,0.22), 0 4px 16px rgba(58,84,176,0.12)",
                            }}
                        >
                            {/* Header */}
                            <div
                                className="px-5 pt-4 pb-3 flex items-center justify-between"
                                style={{ borderBottom: "1px solid rgba(0,0,0,0.06)" }}
                            >
                                <div className="flex items-center gap-2">
                                    <div
                                        className="w-6 h-6 rounded-lg flex items-center justify-center text-white text-xs font-black"
                                        style={{ background: "#3a54b0" }}
                                    >
                                        {currentStep + 1}
                                    </div>
                                    <span className="text-sm font-bold" style={{ color: "#1a1d3a" }}>
                                        {step.title}
                                    </span>
                                </div>
                                <button
                                    onClick={onSkip}
                                    className="w-7 h-7 rounded-full flex items-center justify-center transition-colors hover:bg-slate-100"
                                    title="Skip tour"
                                >
                                    <X className="w-4 h-4 text-slate-400" />
                                </button>
                            </div>

                            {/* Body */}
                            <div className="px-5 py-4">
                                <p className="text-sm leading-relaxed" style={{ color: "#4b5563" }}>
                                    {step.description}
                                </p>
                            </div>

                            {/* Footer */}
                            <div
                                className="px-5 pb-4 flex items-center justify-between"
                            >
                                {/* Step dots */}
                                <div className="flex items-center gap-1.5">
                                    {steps.map((_, i) => (
                                        <div
                                            key={i}
                                            className="rounded-full transition-all duration-200"
                                            style={{
                                                width: i === currentStep ? 16 : 6,
                                                height: 6,
                                                background: i === currentStep
                                                    ? "#3a54b0"
                                                    : "rgba(58,84,176,0.20)",
                                            }}
                                        />
                                    ))}
                                </div>

                                {/* Controls */}
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={onSkip}
                                        className="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                                        style={{ color: "#94a3b8" }}
                                    >
                                        Skip
                                    </button>
                                    {!isFirst && (
                                        <button
                                            onClick={onPrev}
                                            className="p-1.5 rounded-lg border transition-colors hover:bg-slate-50"
                                            style={{ borderColor: "rgba(58,84,176,0.25)", color: "#3a54b0" }}
                                        >
                                            <ChevronLeft className="w-4 h-4" />
                                        </button>
                                    )}
                                    <button
                                        onClick={isLast ? onFinish : onNext}
                                        className="px-4 py-1.5 text-xs font-bold text-white rounded-lg transition-all hover:opacity-90"
                                        style={{ background: "#3a54b0" }}
                                    >
                                        {isLast ? "Selesai" : "Lanjut"}
                                        {!isLast && <ChevronRight className="w-3.5 h-3.5 inline ml-0.5 -mr-0.5" />}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* ── Floating Help Button (always visible) ── */}
            <AnimatePresence>
                {!isOpen && (
                    <motion.button
                        key="help-btn"
                        initial={{ opacity: 0, scale: 0.8 }}
                        animate={{ opacity: 1, scale: 1 }}
                        exit={{ opacity: 0, scale: 0.8 }}
                        transition={{ duration: 0.2, delay: 1 }}
                        onClick={onStart}
                        title="Lihat panduan"
                        className="fixed z-[8000] bottom-6 right-6 w-11 h-11 rounded-full flex items-center justify-center shadow-lg transition-all hover:scale-110 active:scale-95"
                        style={{
                            background: "#3a54b0",
                            boxShadow: "0 6px 20px rgba(58,84,176,0.35)",
                        }}
                    >
                        <HelpCircle className="w-5 h-5 text-white" />
                    </motion.button>
                )}
            </AnimatePresence>
        </>,
        document.body
    );
}
