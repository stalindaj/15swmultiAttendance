export const personLine = (p) => [p?.rank, p?.name].filter(Boolean).join(' ');

export const personMeta = (p) => [p?.squadron, p?.office, p?.serial && `SN ${p.serial}`].filter(Boolean).join(' · ');

export const offDuty = (p) => p?.psr_status && p.psr_status !== 'ON DUTY';
