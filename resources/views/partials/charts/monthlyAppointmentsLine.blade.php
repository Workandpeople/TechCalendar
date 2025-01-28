<div class="card">
    <div class="card-header">
        Nombre de rendez-vous menés à bien par mois
    </div>
    <div class="card-body">
        <canvas id="monthlyAppointmentsLineChart"></canvas>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const appointmentsData = @json($appointmentsMonthlyLine);

        // appointmentsByMonth[ "2025-02" ] = { label: "février 2025", count: 5 }
        const appointmentsByMonth = {};

        appointmentsData.forEach(a => {
            if (a.end_at) {
                const dateObj = new Date(a.end_at);
                const year  = dateObj.getFullYear();
                const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                const key   = `${year}-${month}`;

                const label = dateObj.toLocaleString('default', { month: 'long', year: 'numeric' });

                if (!appointmentsByMonth[key]) {
                    appointmentsByMonth[key] = { label: label, count: 0 };
                }
                appointmentsByMonth[key].count++;
            }
        });

        // Trier les clés YYYY-MM
        const sortedKeys = Object.keys(appointmentsByMonth).sort();

        const labels = sortedKeys.map(k => appointmentsByMonth[k].label);
        const data   = sortedKeys.map(k => appointmentsByMonth[k].count);

        const ctx = document.getElementById('monthlyAppointmentsLineChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Rendez-vous',
                    data: data,
                    borderColor: '#28a745',
                    fill: false,
                }]
            },
        });
    });
</script>
