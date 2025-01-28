<div class="card">
    <div class="card-header">
        Rendez-vous effectués et à venir
    </div>
    <div class="card-body">
        <canvas id="appointmentsPieChart" style="max-width: 300px; max-height: 300px;"></canvas>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // On récupère seulement les rendez-vous pour le pie chart
        const appointmentsData = @json($appointmentsPie);
        const now = new Date();

        const completed = appointmentsData.filter(a => new Date(a.end_at) < now).length;
        const upcoming  = appointmentsData.filter(a => new Date(a.start_at) >= now).length;

        const ctx = document.getElementById('appointmentsPieChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Effectués', 'À venir'],
                datasets: [{
                    data: [completed, upcoming],
                    backgroundColor: ['#28a745', '#ffc107'],
                }]
            },
        });
    });
</script>
