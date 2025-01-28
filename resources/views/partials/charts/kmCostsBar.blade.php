<div class="card">
    <div class="card-header">
        Coûts kilométriques par mois
    </div>
    <div class="card-body">
        <canvas id="kmCostsBarChart"></canvas>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const appointmentsData = @json($appointmentsKmCost);

        // On va stocker : costsByMonth[ "2025-01" ] = { label: "janvier 2025", cost: 123.45 }
        const costsByMonth = {};

        appointmentsData.forEach(a => {
            if (a.trajet_distance) {
                const dateObj = new Date(a.start_at);
                const year  = dateObj.getFullYear();
                const month = String(dateObj.getMonth() + 1).padStart(2, '0'); // 01 à 12
                const key   = `${year}-${month}`; // ex: "2025-02"

                // Label lisible, ex: "février 2025"
                const label = dateObj.toLocaleString('default', { month: 'long', year: 'numeric' });

                if (!costsByMonth[key]) {
                    costsByMonth[key] = { label: label, cost: 0 };
                }
                costsByMonth[key].cost += (a.trajet_distance * 0.5);
            }
        });

        // On trie les clés "YYYY-MM"
        const sortedKeys = Object.keys(costsByMonth).sort();
        // => ex: ["2025-01", "2025-02", "2025-03", "2025-04"]

        // On construit les labels et data dans l'ordre
        const labels = sortedKeys.map(k => costsByMonth[k].label);
        const data   = sortedKeys.map(k => costsByMonth[k].cost);

        const ctx = document.getElementById('kmCostsBarChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Coût (€)',
                    data: data,
                    backgroundColor: '#007bff',
                }]
            },
        });
    });
</script>
