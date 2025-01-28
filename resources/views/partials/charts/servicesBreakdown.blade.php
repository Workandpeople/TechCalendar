<div class="card">
    <div class="card-header">
        Répartition des services par type
    </div>
    <div class="card-body">
        <canvas id="servicesBreakdownChart" style="max-width: 300px; max-height: 300px;"></canvas>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const appointmentsData = @json($appointmentsServices);
        const servicesData     = @json($services);

        // Compter les services par type
        const serviceCounts = {};
        appointmentsData.forEach(appointment => {
            const service = servicesData.find(s => s.id === appointment.service_id);
            if (service) {
                serviceCounts[service.type] = (serviceCounts[service.type] || 0) + 1;
            }
        });

        const labels = Object.keys(serviceCounts);
        const data   = Object.values(serviceCounts);

        const ctx = document.getElementById('servicesBreakdownChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Répartition des services',
                    data: data,
                    backgroundColor: ['#007bff', '#ffc107', '#28a745', '#dc3545', '#6c757d'],
                }]
            },
        });
    });
</script>
