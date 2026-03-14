import React from "react"
import { usePage } from "@inertiajs/react"

import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    Tooltip,
    PieChart,
    Pie,
    LineChart,
    Line
} from "recharts"

export default function Dashboard() {

    const { analytics } = usePage().props

    return (
        <div className="p-8 space-y-12">

            <h1 className="text-2xl font-bold">
                AI Orchestrator Analytics
            </h1>

            {/* Intent Distribution */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    Intent Distribution
                </h2>

                <BarChart width={600} height={300} data={analytics.intentDistribution}>
                    <XAxis dataKey="intent" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="total" />
                </BarChart>
            </div>

            {/* Outcome Distribution */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    Outcome Quality
                </h2>

                <PieChart width={400} height={300}>
                    <Pie
                        data={analytics.outcomeDistribution}
                        dataKey="total"
                        nameKey="outcome"
                    />
                    <Tooltip />
                </PieChart>
            </div>

            {/* Latency */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    Agent Latency
                </h2>

                <BarChart width={600} height={300} data={analytics.latencyByAgent}>
                    <XAxis dataKey="intent" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="avg_latency" />
                </BarChart>
            </div>

            {/* Cost */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    AI Cost Over Time
                </h2>

                <LineChart width={700} height={300} data={analytics.dailyCost}>
                    <XAxis dataKey="day" />
                    <YAxis />
                    <Tooltip />
                    <Line type="monotone" dataKey="cost" />
                </LineChart>
            </div>

            {/* Agent Complexity */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    Multi-Agent Complexity
                </h2>

                <BarChart width={600} height={300} data={analytics.agentComplexity}>
                    <XAxis dataKey="agent_count" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="turns" />
                </BarChart>
            </div>

            {/* Token Usage */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    Token Usage
                </h2>

                <BarChart width={700} height={300} data={analytics.tokenUsage}>
                    <XAxis dataKey="day" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="input_tokens" />
                    <Bar dataKey="output_tokens" />
                </BarChart>
            </div>

            {/* Failures */}

            <div>
                <h2 className="text-lg font-semibold mb-4">
                    Agent Failures
                </h2>

                <BarChart width={600} height={300} data={analytics.agentFailures}>
                    <XAxis dataKey="intent" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="failures" />
                </BarChart>
            </div>

        </div>
    )
}
