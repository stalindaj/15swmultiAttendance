import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Panel } from '../ui';

const COLORS = { present: '#117a3d', excused: '#a8b3c2', unaccounted: '#d64b3f', arrivals: '#1f3864' };

export function SquadronChart({ rows }) {
    const height = Math.max(220, rows.length * 30 + 60);
    return (
        <Panel title="By squadron">
            <div className="px-2 pt-3" style={{ height }}>
                <ResponsiveContainer>
                    <BarChart data={rows} layout="vertical" margin={{ left: 8, right: 16 }} barCategoryGap={6}>
                        <CartesianGrid horizontal={false} stroke="#e7ebf0" />
                        <XAxis type="number" allowDecimals={false} tick={{ fontSize: 12 }} />
                        <YAxis type="category" dataKey="squadron" width={78} tick={{ fontSize: 12 }} />
                        <Tooltip cursor={{ fill: '#f3f5f8' }} />
                        <Legend wrapperStyle={{ fontSize: 12 }} />
                        <Bar dataKey="present" name="Present" stackId="a" fill={COLORS.present} />
                        <Bar dataKey="unaccounted" name="Unaccounted" stackId="a" fill={COLORS.unaccounted} />
                        <Bar dataKey="excused" name="Excused (PSR)" stackId="a" fill={COLORS.excused} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className="th">Squadron</th>
                            <th className="th text-right">Present</th>
                            <th className="th text-right">Unacc.</th>
                            <th className="th text-right">Exc.</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((s) => (
                            <tr key={s.squadron}>
                                <td className="td">{s.squadron}</td>
                                <td className="td text-right tabular-nums"><b>{s.present}</b> / {s.total}</td>
                                <td className="td text-right text-bad tabular-nums">{s.unaccounted || ''}</td>
                                <td className="td text-right text-muted tabular-nums">{s.excused || ''}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

export function ArrivalsChart({ rows }) {
    return (
        <Panel title="Arrivals" subtitle="People timed in per 10 minutes">
            {rows.length ? (
                <div className="h-48 px-2 pt-3">
                    <ResponsiveContainer>
                        <BarChart data={rows} margin={{ left: -16, right: 12 }}>
                            <CartesianGrid vertical={false} stroke="#e7ebf0" />
                            <XAxis dataKey="time" tick={{ fontSize: 12 }} />
                            <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                            <Tooltip cursor={{ fill: '#f3f5f8' }} />
                            <Bar dataKey="count" name="Timed in" fill={COLORS.arrivals} radius={[3, 3, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            ) : (
                <p className="px-4 py-6 text-center text-sm text-muted">No one has timed in yet.</p>
            )}
        </Panel>
    );
}
