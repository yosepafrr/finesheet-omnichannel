import { useState, useEffect, useCallback } from "react";

/**
 * useOnboarding — manages tour state for a specific page tour
 * @param {string} tourKey - unique key, e.g. "tour_dashboard"
 * @param {number} totalSteps - how many steps this tour has
 */
export function useOnboarding(tourKey, totalSteps) {
    const storageKey = `finesheet_${tourKey}_done`;

    const [isOpen, setIsOpen] = useState(false);
    const [currentStep, setCurrentStep] = useState(0);

    // Auto-open on first visit (only once per browser)
    useEffect(() => {
        const alreadySeen = localStorage.getItem(storageKey);
        if (!alreadySeen) {
            const timer = setTimeout(() => setIsOpen(true), 800);
            return () => clearTimeout(timer);
        }
    }, [storageKey]);

    const start = useCallback(() => {
        setCurrentStep(0);
        setIsOpen(true);
    }, []);

    const next = useCallback(() => {
        setCurrentStep((s) => {
            if (s + 1 >= totalSteps) {
                localStorage.setItem(storageKey, "1");
                setIsOpen(false);
                return 0;
            }
            return s + 1;
        });
    }, [totalSteps, storageKey]);

    const prev = useCallback(() => {
        setCurrentStep((s) => Math.max(0, s - 1));
    }, []);

    const skip = useCallback(() => {
        localStorage.setItem(storageKey, "1");
        setIsOpen(false);
        setCurrentStep(0);
    }, [storageKey]);

    const finish = useCallback(() => {
        localStorage.setItem(storageKey, "1");
        setIsOpen(false);
        setCurrentStep(0);
    }, [storageKey]);

    return { isOpen, currentStep, start, next, prev, skip, finish };
}
