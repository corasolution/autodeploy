import { useEffect, useRef, useState } from 'react';

const STATUS_COLORS = {
    success: 'text-green-400',
    error:   'text-red-400',
    warning: 'text-yellow-400',
    info:    'text-blue-300',
};

export default function Terminal({ deploymentId, initialLogs = [] }) {
    const [logs, setLogs]     = useState(initialLogs);
    const bottomRef           = useRef(null);

    useEffect(() => {
        if (!window.Echo || !deploymentId) return;

        const channel = window.Echo.channel(`deployments.${deploymentId}`);

        channel.listen('.phase.completed', (data) => {
            setLogs((prev) => [
                ...prev,
                {
                    id:      Date.now(),
                    phase:   data.phase,
                    step:    0,
                    status:  'info',
                    output:  `[Phase ${data.phase}] ${data.message}`,
                    logged_at: new Date().toISOString(),
                },
            ]);
        });

        return () => {
            window.Echo.leaveChannel(`deployments.${deploymentId}`);
        };
    }, [deploymentId]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [logs]);

    return (
        <div className="bg-gray-950 rounded-lg border border-gray-800 font-mono text-sm overflow-y-auto max-h-[600px] p-4">
            {logs.length === 0 && (
                <p className="text-gray-500">Waiting for deployment output...</p>
            )}
            {logs.map((log, i) => (
                <div key={log.id ?? i} className="mb-1">
                    <span className="text-gray-600 mr-2">[P{log.phase}:{log.step}]</span>
                    {log.command && (
                        <span className="text-gray-400 mr-2">$ {log.command}</span>
                    )}
                    <span className={STATUS_COLORS[log.status] ?? 'text-gray-300'}>
                        {log.output}
                    </span>
                </div>
            ))}
            <div ref={bottomRef} />
        </div>
    );
}
