import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { motion, AnimatePresence } from "framer-motion";
import { X, ChevronLeft, ChevronRight, HelpCircle } from "lucide-react";

const VIEWPORT_PADDING = 16;
const TARGET_GAP = 16;

function findVisibleTarget(selector) {
    if (!selector) return null;

    return Array.from(document.querySelectorAll(selector)).find((element) => {
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 0
            && rect.height > 0
            && style.display !== "none"
            && style.visibility !== "hidden";
    }) || null;
}

function overlapArea(first, second) {
    const width = Math.max(0, Math.min(first.right, second.right) - Math.max(first.left, second.left));
    const height = Math.max(0, Math.min(first.bottom, second.bottom) - Math.max(first.top, second.top));
    return width * height;
}

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

        let animationFrame = null;
        let observedElement = null;
        const resizeObserver = new ResizeObserver(() => scheduleMeasure());

        const measure = () => {
            animationFrame = null;
            const el = findVisibleTarget(step.selector);

            if (el) {
                if (el !== observedElement) {
                    resizeObserver.disconnect();
                    resizeObserver.observe(el);
                    observedElement = el;
                }

                const rect = el.getBoundingClientRect();
                const nextRect = {
                    top: rect.top,
                    left: rect.left,
                    width: rect.width,
                    height: rect.height,
                };

                setTargetRect((current) => {
                    if (current
                        && Math.abs(current.top - nextRect.top) < 0.5
                        && Math.abs(current.left - nextRect.left) < 0.5
                        && Math.abs(current.width - nextRect.width) < 0.5
                        && Math.abs(current.height - nextRect.height) < 0.5) {
                        return current;
                    }
                    return nextRect;
                });
            } else {
                resizeObserver.disconnect();
                observedElement = null;
                setTargetRect(null);
            }
        };

        const scheduleMeasure = () => {
            if (animationFrame !== null) return;
            animationFrame = window.requestAnimationFrame(measure);
        };

        const revealTarget = () => {
            const el = findVisibleTarget(step.selector);
            if (el) {
                el.scrollIntoView({
                    behavior: window.innerWidth < 768 ? "auto" : "smooth",
                    block: "center",
                    inline: "nearest",
                });
            }
            scheduleMeasure();
        };

        const initialTimer = setTimeout(revealTarget, 120);
        const settledTimer = setTimeout(scheduleMeasure, 450);
        const targetPoller = setInterval(scheduleMeasure, 300);
        window.addEventListener("resize", scheduleMeasure);
        window.addEventListener("scroll", scheduleMeasure, true);

        return () => {
            clearTimeout(initialTimer);
            clearTimeout(settledTimer);
            clearInterval(targetPoller);
            resizeObserver.disconnect();
            if (animationFrame !== null) {
                window.cancelAnimationFrame(animationFrame);
            }
            window.removeEventListener("resize", scheduleMeasure);
            window.removeEventListener("scroll", scheduleMeasure, true);
        };
    }, [isOpen, currentStep, step]);

    // Position tooltip relative to target rect
    useEffect(() => {
        const positionTooltip = () => {
            const tooltip = tooltipRef.current;
            const tooltipWidth = Math.min(320, window.innerWidth - (VIEWPORT_PADDING * 2));
            const tooltipHeight = tooltip?.offsetHeight || 190;
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            if (!targetRect || !tooltip) {
                setTooltipPos({
                    top: Math.max(VIEWPORT_PADDING, (viewportHeight - tooltipHeight) / 2),
                    left: Math.max(VIEWPORT_PADDING, (viewportWidth - tooltipWidth) / 2),
                });
                return;
            }

            const target = {
                top: targetRect.top,
                left: targetRect.left,
                right: targetRect.left + targetRect.width,
                bottom: targetRect.top + targetRect.height,
            };
            const preferred = step?.position || "bottom";
            const placements = [preferred, "bottom", "top", "right", "left"]
                .filter((placement, index, all) => all.indexOf(placement) === index);

            const rawPosition = (placement) => {
                if (placement === "top") {
                    return {
                        top: target.top - tooltipHeight - TARGET_GAP,
                        left: target.left + (targetRect.width - tooltipWidth) / 2,
                    };
                }
                if (placement === "right") {
                    return {
                        top: target.top + (targetRect.height - tooltipHeight) / 2,
                        left: target.right + TARGET_GAP,
                    };
                }
                if (placement === "left") {
                    return {
                        top: target.top + (targetRect.height - tooltipHeight) / 2,
                        left: target.left - tooltipWidth - TARGET_GAP,
                    };
                }
                return {
                    top: target.bottom + TARGET_GAP,
                    left: target.left + (targetRect.width - tooltipWidth) / 2,
                };
            };

            const toRect = (position) => ({
                ...position,
                right: position.left + tooltipWidth,
                bottom: position.top + tooltipHeight,
            });
            const fitsViewport = (position) => position.top >= VIEWPORT_PADDING
                && position.left >= VIEWPORT_PADDING
                && position.left + tooltipWidth <= viewportWidth - VIEWPORT_PADDING
                && position.top + tooltipHeight <= viewportHeight - VIEWPORT_PADDING;

            for (const placement of placements) {
                const candidate = rawPosition(placement);
                if (fitsViewport(candidate) && overlapArea(toRect(candidate), target) === 0) {
                    setTooltipPos(candidate);
                    return;
                }
            }

            const candidates = placements.map((placement) => {
                const raw = rawPosition(placement);
                const clamped = {
                    top: Math.max(VIEWPORT_PADDING, Math.min(raw.top, viewportHeight - tooltipHeight - VIEWPORT_PADDING)),
                    left: Math.max(VIEWPORT_PADDING, Math.min(raw.left, viewportWidth - tooltipWidth - VIEWPORT_PADDING)),
                };

                return {
                    ...clamped,
                    overlap: overlapArea(toRect(clamped), target),
                };
            }).sort((first, second) => first.overlap - second.overlap);

            setTooltipPos({ top: candidates[0].top, left: candidates[0].left });
        };

        positionTooltip();
        const tooltipObserver = new ResizeObserver(positionTooltip);
        if (tooltipRef.current) tooltipObserver.observe(tooltipRef.current);
        window.addEventListener("resize", positionTooltip);

        return () => {
            tooltipObserver.disconnect();
            window.removeEventListener("resize", positionTooltip);
        };
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
                        style={{ background: targetRect ? "transparent" : "rgba(10, 12, 30, 0.60)" }}
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
                                    fill="rgba(10, 12, 30, 0.60)"
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
                                data-tour-highlight="true"
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
            <AnimatePresence mode="wait">
                {isOpen && step && (
                    <motion.div
                        ref={tooltipRef}
                        initial={{ opacity: 0, y: 10, scale: 0.97 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 8, scale: 0.96 }}
                        transition={{ duration: 0.22, ease: [0.16, 1, 0.3, 1] }}
                        data-tour-tooltip="true"
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
