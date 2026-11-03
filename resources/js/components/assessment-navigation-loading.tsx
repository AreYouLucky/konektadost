import AssessmentLoading from '@/components/assessment-loading';
import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export default function AssessmentNavigationLoading() {
    const [loading, setLoading] = useState(false);
    const activeVisit = useRef<PendingVisit | null>(null);

    useEffect(() => {
        const removeStartListener = router.on('start', ({ detail: { visit } }) => {
            const path = visit.url.pathname.replace(/\/$/, '');
            if (visit.prefetch || visit.async || !['/location/assess', '/admin/assessment/start'].some((suffix) => path.endsWith(suffix))) return;

            activeVisit.current = visit;
            setLoading(true);
        });
        const removeFinishListener = router.on('finish', ({ detail: { visit } }) => {
            // An unrelated background request must not hide the pending assessment.
            if (activeVisit.current !== visit) return;
            activeVisit.current = null;
            setLoading(false);
        });

        return () => {
            removeStartListener();
            removeFinishListener();
        };
    }, []);

    return <AssessmentLoading open={loading} onCancel={() => router.cancelAll({ async: false, prefetch: false })} />;
}
