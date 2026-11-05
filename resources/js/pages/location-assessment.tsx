import AsyncLocationAssessment, { type PendingAssessment } from '@/components/async-location-assessment';
import { LocationAssessmentReport } from './location-assessment/assessment-report';
import type { AssessmentPageProps } from './location-assessment/types';
export type { AssessmentPageProps } from './location-assessment/types';
export default function LocationAssessment(props: AssessmentPageProps | { pendingAssessment: PendingAssessment }) {
    if ('pendingAssessment' in props)
        return <AsyncLocationAssessment pending={props.pendingAssessment} renderReport={(report) => <LocationAssessmentReport {...report} />} />;
    return <LocationAssessmentReport {...props} />;
}
