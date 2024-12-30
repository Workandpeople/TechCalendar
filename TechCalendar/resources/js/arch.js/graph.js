document.addEventListener('DOMContentLoaded', () => {
    const charts = {};

    function initializeCharts() {
        const ctxDistance = document.getElementById('distanceChart').getContext('2d');
        const ctxRdv = document.getElementById('rdvChart').getContext('2d');
        const ctxTravelTime = document.getElementById('travelTimeChart').getContext('2d');
        const ctxAppointmentTime = document.getElementById('appointmentTimeChart').getContext('2d');

        charts.distanceChart = new Chart(ctxDistance, {
            type: 'bar',
            data: { labels: [], datasets: [{ label: 'Kilomètres', data: [] }] },
        });

        charts.rdvChart = new Chart(ctxRdv, {
            type: 'bar',
            data: { labels: [], datasets: [{ label: 'Nombre de RDV', data: [] }] },
        });

        charts.travelTimeChart = new Chart(ctxTravelTime, {
            type: 'bar',
            data: { labels: [], datasets: [{ label: 'Temps de trajet (min)', data: [] }] },
        });

        charts.appointmentTimeChart = new Chart(ctxAppointmentTime, {
            type: 'bar',
            data: { labels: [], datasets: [{ label: 'Temps des RDV (min)', data: [] }] },
        });
    }

    function updateCharts() {
        const selectedTechItems = Array.from(document.querySelectorAll('.tech-checkbox:checked'))
            .map(checkbox => checkbox.closest('.tech-item'));
    
        console.log('Techniciens sélectionnés :', selectedTechItems);
    
        if (selectedTechItems.length === 0) {
            console.warn('Aucun technicien sélectionné.');
            resetCharts();
            return;
        }
    
        // Récupérer les dates sélectionnées
        const startDate = new Date(document.querySelector('input[name="start_date"]').value);
        const endDate = new Date(document.querySelector('input[name="end_date"]').value);
        console.log('Période sélectionnée :', { startDate, endDate });
    
        const chartData = selectedTechItems.map(item => {
            const rdvData = JSON.parse(item.dataset.rdv);
            console.log(`Données brutes pour ${item.dataset.name} :`, rdvData);
    
            // Filtrer les rendez-vous pour la période sélectionnée
            const filteredRdv = rdvData.filter(rdv => {
                if (!rdv.date) {
                    console.warn('Rendez-vous sans date :', rdv);
                    return false;
                }
                const rdvDate = new Date(rdv.date);
                const isInDateRange = rdvDate >= startDate && rdvDate <= endDate;
                console.log(`Rendez-vous (${rdv.date}) est dans la plage : ${isInDateRange}`);
                return isInDateRange;
            });
    
            console.log(`Données filtrées pour ${item.dataset.name} :`, filteredRdv);
    
            // Calculer les données agrégées
            const aggregatedData = {
                name: item.dataset.name,
                distance: filteredRdv.reduce((sum, rdv) => sum + (rdv.distance || 0), 0),
                rdv: filteredRdv.length,
                travelTime: filteredRdv.reduce((sum, rdv) => sum + (rdv.travelTime || 0), 0),
                appointmentTime: filteredRdv.reduce((sum, rdv) => sum + (rdv.appointmentTime || 0), 0),
            };
            console.log(`Données agrégées pour ${item.dataset.name} :`, aggregatedData);
    
            return aggregatedData;
        });
    
        console.log('Données finales pour les graphiques :', chartData);
    
        // Mettre à jour les graphiques avec les données filtrées
        updateChart(charts.distanceChart, chartData, 'distance');
        updateChart(charts.rdvChart, chartData, 'rdv');
        updateChart(charts.travelTimeChart, chartData, 'travelTime');
        updateChart(charts.appointmentTimeChart, chartData, 'appointmentTime');
    }

    function resetCharts() {
        Object.values(charts).forEach(chart => {
            chart.data.labels = [];
            chart.data.datasets[0].data = [];
            chart.update();
        });
    }

    function updateChart(chart, data, key) {
        chart.data.labels = data.map(d => d.name);
        chart.data.datasets[0].data = data.map(d => d[key]);
        chart.update();
    }

    function filterTechnicians() {
        const searchValue = document.getElementById('searchTechniciansInGraph').value.toLowerCase();
        const techItems = document.querySelectorAll('.tech-item');

        techItems.forEach(item => {
            const name = item.dataset.name;
            if (name.startsWith(searchValue)) {
                item.classList.remove('hidden');
            } else {
                item.classList.add('hidden');
            }
        });
    }

    function updateTechniciansByDate() {
        const startDate = new Date(document.querySelector('input[name="start_date"]').value);
        const endDate = new Date(document.querySelector('input[name="end_date"]').value);
        const techItems = document.querySelectorAll('.tech-item');

        techItems.forEach(item => {
            const rdvData = JSON.parse(item.dataset.rdv); // Récupérer les rendez-vous depuis le dataset
            const id = item.dataset.id;

            // Filtrer les rendez-vous pour la période sélectionnée
            const filteredRdv = rdvData.filter(rdv => {
                const rdvDate = new Date(rdv.date);
                return rdvDate >= startDate && rdvDate <= endDate;
            });

            // Mettre à jour l'affichage du nombre de rendez-vous
            const currentPeriodCount = item.querySelector('.current-period-count');
            currentPeriodCount.textContent = filteredRdv.length;

            // Mettre à jour les données du dataset pour les graphiques
            rendezvousData[id] = filteredRdv;
        });

        // Mettre à jour les graphiques avec les nouvelles données
        updateCharts();
    }

    initializeCharts();

    document.querySelectorAll('.tech-checkbox').forEach(checkbox =>
        checkbox.addEventListener('change', updateCharts)
    );

    document.getElementById('searchTechniciansInGraph').addEventListener('input', filterTechnicians);

    document.querySelectorAll('input[name="start_date"], input[name="end_date"]').forEach(input =>
        input.addEventListener('change', updateTechniciansByDate)
    );
});