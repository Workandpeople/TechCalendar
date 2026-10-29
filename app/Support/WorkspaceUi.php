<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Presentation only: this catalogue never grants access or builds navigation links. */
final class WorkspaceUi
{
    public static function forRoute(?string $route): array
    {
        $area = match (true) {
            Str::is(['profile', 'profile.*'], $route ?? '') => 'profile',
            Str::is('client.lots*', $route ?? '') => 'client-lots',
            Str::is('client.*', $route ?? '') => 'client-dossiers',
            Str::is('v2.dossiers.*', $route ?? '') => 'dossiers',
            Str::is(['planner.book*', 'planner.replace*', 'manager.replace*'], $route ?? '') => 'booking',
            Str::is(['*.lots*'], $route ?? '') => 'lots',
            Str::is(['*.tracking*', '*.appointments*', 'tech.*'], $route ?? '') => 'planning',
            Str::is(['*.clients*', '*.delegataires*'], $route ?? '') => 'clients',
            Str::is('*.mail-*', $route ?? '') => 'mail',
            Str::is('*.services*', $route ?? '') => 'services',
            Str::is('*.users*', $route ?? '') => 'users',
            Str::is('admin.settings*', $route ?? '') => 'settings',
            default => 'dashboard',
        };

        [$label, $tone, $icon, $steps] = match ($area) {
            'profile' => ['Mon profil', 'cyan', 'users', [
                ['Mes informations', 'Retrouvez votre identité, votre adresse de connexion et votre organisation.'],
                ['Sécuriser mon compte', 'Modifiez votre mot de passe avec votre mot de passe actuel.'],
                ['Mes notifications', 'Choisissez les événements pour lesquels recevoir un email, uniquement sur les dossiers auxquels vous avez accès.'],
            ]],
            'dossiers' => ['Les dossiers', 'blue', 'dossiers', [
                ['Retrouver', 'Choisissez un état ou recherchez un client, une référence ou une adresse.'],
                ['Consulter', 'Ouvrez le dossier pour retrouver ses prestations, rendez-vous et documents.'],
                ['Faire avancer', 'Les actions de suivi dépendent de l’état de chaque prestation. Vérifiez la visibilité des pièces et commentaires partagés.'],
            ]],
            'booking' => ['Prendre un RDV', 'teal', 'book', [
                ['Choisir le dossier', 'Sélectionnez un dossier à placer ou ouvrez un lot. Le mode téléphone se traite sans déplacement.'],
                ['Comparer les créneaux', 'Pour une visite physique, sélectionnez un technicien puis un créneau proposé. La carte aide à vérifier le trajet.'],
                ['Confirmer', 'Vérifiez les informations avant de placer le rendez-vous. Les actions complémentaires sont proposées après confirmation.'],
            ]],
            'planning' => ['Planning et rendez-vous', 'teal', 'planning', [
                ['Sélectionner', 'Choisissez les techniciens et la période à consulter.'],
                ['Ouvrir le rendez-vous', 'Cliquez sur un rendez-vous pour consulter ses informations, son adresse et ses documents.'],
                ['Intervenir', 'Les actions disponibles dans le détail dépendent de vos droits et de l’état du rendez-vous.'],
            ]],
            'lots' => ['Les lots', 'ochre', 'lots', [
                ['Importer', 'Ajoutez le fichier du lot et définissez son client, sa prestation et ses objectifs.'],
                ['Vérifier', 'Corrigez ou excluez les lignes signalées avant de confirmer l’import.'],
                ['Suivre', 'Ouvrez une carte pour suivre les dossiers, les documents et les résultats physiques ou téléphoniques.'],
            ]],
            'clients' => ['Clients et délégataires', 'cyan', 'delegataires', [
                ['Identifier le client', 'Un client classique ou un délégataire peut être affecté à un dossier ou à un lot.'],
                ['Gérer les accès', 'Les comptes externes sont distincts de votre équipe interne. Leurs droits se règlent dans la fiche client.'],
                ['Partager avec soin', 'Les documents et commentaires internes ne sont pas destinés aux comptes externes.'],
            ]],
            'mail' => ['Mails', 'cyan', 'mail', [
                ['Configurer l’expéditeur', 'Renseignez le compte d’envoi et son identité.'],
                ['Préparer le modèle', 'Rédigez le contenu et utilisez les variables pour personnaliser vos messages.'],
                ['Vérifier l’aperçu', 'Contrôlez le rendu avant utilisation. Un modèle enregistré n’est pas un mail envoyé.'],
            ]],
            'services' => ['Prestations', 'slate', 'services', [
                ['Retrouver', 'Recherchez une prestation par son nom ou son type.'],
                ['Configurer', 'Les durées et habilitations interviennent dans la prise de rendez-vous.'],
                ['Vérifier', 'Contrôlez les informations avant d’enregistrer une modification.'],
            ]],
            'users' => ['Équipe interne', 'slate', 'users', [
                ['Retrouver une personne', 'Filtrez par nom ou par rôle. Les clients externes sont gérés dans Clients et délégataires.'],
                ['Définir le rôle', 'Attribuez uniquement les accès nécessaires à la personne.'],
                ['Préparer le planning', 'Vérifiez l’adresse, les prestations et les disponibilités des techniciens.'],
            ]],
            'settings' => ['Paramètres', 'slate', 'settings', [
                ['Repérer les manques', 'Les notifications du menu signalent les valeurs attendues mais non renseignées.'],
                ['Configurer', 'Vérifiez l’environnement ciblé avant de modifier une connexion externe.'],
                ['Enregistrer', 'Les valeurs saisies ici sont prioritaires sur la configuration du serveur.'],
            ]],
            'client-dossiers' => ['Mes dossiers', 'blue', 'dossiers', [
                ['Retrouver', 'Consultez les dossiers affectés à votre compte.'],
                ['Consulter', 'Les informations et pièces partagées sont accessibles depuis le détail.'],
                ['Répondre', 'En cas de non-conformité, ouvrez la prestation concernée pour transmettre les éléments demandés, selon vos droits.'],
            ]],
            'client-lots' => ['Mes lots', 'ochre', 'lots', [
                ['Retrouver', 'Consultez les lots affectés à votre compte.'],
                ['Suivre', 'Ouvrez un lot pour consulter son avancement et ses dossiers.'],
                ['Consulter les résultats', 'Les suivis physiques et téléphoniques sont séparés lorsque le lot est hybride.'],
            ]],
            default => ['Tableau de bord', 'slate', 'dashboard', [
                ['Se repérer', 'Le menu regroupe vos outils selon votre rôle. Chaque rubrique conserve sa couleur et son pictogramme.'],
                ['Traiter', 'Utilisez les rubriques accessibles dans votre menu pour organiser le suivi des inspections et les rendez-vous.'],
                ['Suivre', 'Les lots regroupent les dossiers importés. Les pastilles rouges du menu signalent les éléments à suivre.'],
            ]],
        };

        return compact('area', 'label', 'tone', 'icon', 'steps');
    }

    public static function status(string $key): array
    {
        return match ($key) {
            'pending' => ['book', 'À planifier'],
            'problem' => ['alert', 'À résoudre'],
            'awaiting_visit' => ['planning', 'Visite programmée'],
            'visited' => ['check', 'À traiter après visite'],
            'rt_control' => ['search', 'Contrôle interne'],
            'non_compliant' => ['alert', 'Réponse du client attendue'],
            'recontrol' => ['search', 'Éléments à revérifier'],
            'report' => ['document', 'Rapport finalisé'],
            'invoiced' => ['check', 'Facturation effectuée'],
            'cancelled' => ['close', 'Dossier annulé'],
            'unknown' => ['trash', 'Suppression réversible'],
            'all' => ['dossiers', 'Vue d’ensemble'],
            default => ['dossiers', 'État à vérifier'],
        };
    }
}
