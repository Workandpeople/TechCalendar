// Utilisation de @json pour encoder les données de manière sûre
const distanceData = @json($distanceData ?? []);
const rdvData = @json($rdvData ?? []);
const travelTimeData = @json($travelTimeData ?? []);
const appointmentTimeData = @json($appointmentTimeData ?? []);

// Initialisation des graphiques
new Chart(document.getElementById('distanceChart'), {
    type: 'bar',
    data: {
        labels: Object.keys(distanceData),
        datasets: [{
            label: 'Kilomètres',
            data: Object.values(distanceData),
            backgroundColor: 'rgba(54, 162, 235, 0.5)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
        }]
    }
});

new Chart(document.getElementById('rdvChart'), {
    type: 'bar',
    data: {
        labels: Object.keys(rdvData),
        datasets: [{
            label: 'RDV',
            data: Object.values(rdvData),
            backgroundColor: 'rgba(75, 192, 192, 0.5)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 1
        }]
    }
});

new Chart(document.getElementById('travelTimeChart'), {
    type: 'bar',
    data: {
        labels: Object.keys(travelTimeData),
        datasets: [{
            label: 'Heures de trajet',
            data: Object.values(travelTimeData),
            backgroundColor: 'rgba(255, 206, 86, 0.5)',
            borderColor: 'rgba(255, 206, 86, 1)',
            borderWidth: 1
        }]
    }
});

new Chart(document.getElementById('appointmentTimeChart'), {
    type: 'bar',
    data: {
        labels: Object.keys(appointmentTimeData),
        datasets: [{
            label: 'Heures de RDV',
            data: Object.values(appointmentTimeData),
            backgroundColor: 'rgba(153, 102, 255, 0.5)',
            borderColor: 'rgba(153, 102, 255, 1)',
            borderWidth: 1
        }]
    }
});