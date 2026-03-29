(function () {
    'use strict';

    window.PATHLAB_CONSTANTS = Object.freeze({
        apiUrl: 'api/path_data.php',
        calculationLookbackDays: 21,
        graphDays: 3,
        chart: {
            width: 1200,
            height: 420,
            margin: {
                top: 20,
                right: 18,
                bottom: 70,
                left: 52
            }
        },
        palette: {
            path: '#9ce365',
            actual: '#ffd166',
            solar: 'rgba(253, 214, 88, 0.26)',
            usage: 'rgba(233, 117, 96, 0.22)'
        }
    });
})();
