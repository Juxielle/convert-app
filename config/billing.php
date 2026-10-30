<?php

return [
    'trial_requires_payment' => true,
    'payment_methods' => [
        'card' => ['label' => 'Carte bancaire', 'description' => 'Paiement sur la page sécurisée du prestataire.'],
        'mobile_money' => ['label' => 'Mobile Money', 'description' => 'Validation du paiement avec votre opérateur mobile.'],
    ],
    'trial_policy' => 'Un seul essai par compte, toutes formules confondues. Un changement de formule ou de périodicité ne renouvelle pas l’essai.',
    'quote_minutes' => 10,
    'monthly_window_days' => 30,
    'advanced_tools' => ['supprimer-fond', 'ocr-image', 'transcrire-media'],
    'entitlements' => ['priority_processing', 'advanced_conversions', 'batch_processing', 'priority_support'],
    'feature_labels' => ['priority_processing' => 'Traitement prioritaire', 'advanced_conversions' => 'Conversions avancées', 'batch_processing' => 'Traitement par lots', 'priority_support' => 'Support prioritaire'],
];
