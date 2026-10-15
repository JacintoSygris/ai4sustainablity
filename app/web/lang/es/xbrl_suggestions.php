<?php

return [
    'description' => 'Revisar sugerencias offline de conceptos XBRL; exige aceptación humana.',
    'usage' => 'Seleccione --list, --accept=ID o --reject=ID. Para decidir, indique --reviewer con un alias de operador responsable.',
    'headers' => ['ID de sugerencia', 'Indicador NEIS', 'Concepto XBRL', 'Puntuación sin calibrar', 'Decisión'],
    'pending_review' => 'Pendiente de revisión',
    'accepted' => 'Aceptada',
    'stale_approval' => 'Aceptación caducada; revisar de nuevo',
    'rejected' => 'Rechazada',
    'recorded' => 'Decisión registrada. El mapa solo usa aceptaciones válidas si se habilita el overlay.',
    'failed' => 'No se pudo completar la revisión. Compruebe los activos, el ID, el alias y las decisiones previas.',
];
