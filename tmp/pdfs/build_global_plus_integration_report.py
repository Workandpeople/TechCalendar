from __future__ import annotations

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    Image,
    ListFlowable,
    ListItem,
    PageBreak,
    PageTemplate,
    Paragraph,
    Preformatted,
    Spacer,
    Table,
    TableStyle,
)

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / "output" / "pdf" / "rapport-integration-techcalendar-global-plus.pdf"
LOGO = ROOT / "public" / "images" / "logo.png"

PAGE_WIDTH, PAGE_HEIGHT = A4
MARGIN_X = 18 * mm
MARGIN_TOP = 17 * mm
MARGIN_BOTTOM = 16 * mm

COLORS = {
    "ink": colors.HexColor("#24323D"),
    "muted": colors.HexColor("#6B7785"),
    "line": colors.HexColor("#DFE6ED"),
    "navy": colors.HexColor("#17263A"),
    "teal": colors.HexColor("#13A6A6"),
    "green": colors.HexColor("#1C9A66"),
    "red": colors.HexColor("#D65A5A"),
    "paper": colors.HexColor("#FAFBFC"),
    "soft_teal": colors.HexColor("#EEF7F7"),
    "code_bg": colors.HexColor("#F4F7FA"),
}

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(
    name="CoverTitle",
    parent=styles["Title"],
    fontName="Helvetica-Bold",
    fontSize=26,
    leading=31,
    textColor=COLORS["navy"],
    alignment=TA_LEFT,
    spaceAfter=12,
))
styles.add(ParagraphStyle(
    name="CoverSubtitle",
    parent=styles["Normal"],
    fontName="Helvetica",
    fontSize=12.2,
    leading=17.5,
    textColor=COLORS["muted"],
    spaceAfter=12,
))
styles.add(ParagraphStyle(
    name="H1Custom",
    parent=styles["Heading1"],
    fontName="Helvetica-Bold",
    fontSize=16.5,
    leading=21,
    textColor=COLORS["navy"],
    spaceBefore=5,
    spaceAfter=8,
))
styles.add(ParagraphStyle(
    name="H2Custom",
    parent=styles["Heading2"],
    fontName="Helvetica-Bold",
    fontSize=12.2,
    leading=15.5,
    textColor=COLORS["ink"],
    spaceBefore=7,
    spaceAfter=5,
))
styles.add(ParagraphStyle(
    name="BodyCustom",
    parent=styles["BodyText"],
    fontName="Helvetica",
    fontSize=9.25,
    leading=13.1,
    textColor=COLORS["ink"],
    spaceAfter=5,
))
styles.add(ParagraphStyle(
    name="SmallMuted",
    parent=styles["BodyText"],
    fontName="Helvetica",
    fontSize=8.1,
    leading=10.8,
    textColor=COLORS["muted"],
))
styles.add(ParagraphStyle(
    name="TableHeader",
    parent=styles["BodyText"],
    fontName="Helvetica-Bold",
    fontSize=7.8,
    leading=9.6,
    textColor=colors.white,
    alignment=TA_LEFT,
))
styles.add(ParagraphStyle(
    name="TableCell",
    parent=styles["BodyText"],
    fontName="Helvetica",
    fontSize=7.8,
    leading=10.1,
    textColor=COLORS["ink"],
))
styles.add(ParagraphStyle(
    name="CalloutTitle",
    parent=styles["BodyText"],
    fontName="Helvetica-Bold",
    fontSize=9.2,
    leading=11.7,
    textColor=COLORS["navy"],
    spaceAfter=3,
))
styles.add(ParagraphStyle(
    name="CodeCustom",
    parent=styles["Code"],
    fontName="Courier",
    fontSize=6.45,
    leading=8.05,
    textColor=colors.HexColor("#1F2937"),
    backColor=COLORS["code_bg"],
))
styles.add(ParagraphStyle(
    name="Footer",
    parent=styles["Normal"],
    fontSize=7.4,
    leading=9,
    textColor=COLORS["muted"],
    alignment=TA_CENTER,
))


def p(text: str, style: str = "BodyCustom") -> Paragraph:
    return Paragraph(text, styles[style])


def bullets(items: list[str]) -> ListFlowable:
    return ListFlowable(
        [ListItem(p(item), leftIndent=8, bulletColor=COLORS["teal"]) for item in items],
        bulletType="bullet",
        start="circle",
        leftIndent=14,
        bulletFontSize=5,
        bulletOffsetY=2,
        spaceAfter=5,
    )


def code_block(text: str) -> Table:
    pre = Preformatted(text.strip(), styles["CodeCustom"], maxLineLength=112)
    table = Table([[pre]], colWidths=[PAGE_WIDTH - 2 * MARGIN_X])
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), COLORS["code_bg"]),
        ("BOX", (0, 0), (-1, -1), 0.45, colors.HexColor("#CFD9E2")),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return table


def section_title(text: str) -> list:
    return [p(text, "H1Custom")]


def callout(title: str, body: str) -> Table:
    table = Table(
        [[p(title, "CalloutTitle"), p(body, "BodyCustom")]],
        colWidths=[42 * mm, PAGE_WIDTH - 2 * MARGIN_X - 42 * mm],
    )
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), COLORS["soft_teal"]),
        ("BOX", (0, 0), (-1, -1), 0.45, colors.HexColor("#BFE4E1")),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 7),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    return table


def data_table(headers: list[str], rows: list[list[str]], widths: list[float] | None = None) -> Table:
    data = [[p(h, "TableHeader") for h in headers]]
    for row in rows:
        data.append([p(cell, "TableCell") for cell in row])
    if widths is None:
        widths = [(PAGE_WIDTH - 2 * MARGIN_X) / len(headers)] * len(headers)
    table = Table(data, colWidths=widths, repeatRows=1)
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), COLORS["navy"]),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("GRID", (0, 0), (-1, -1), 0.35, COLORS["line"]),
        ("BACKGROUND", (0, 1), (-1, -1), colors.white),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, COLORS["paper"]]),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("TOPPADDING", (0, 0), (-1, -1), 4.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4.5),
    ]))
    return table


def draw_header_footer(canvas, doc):
    canvas.saveState()
    page = canvas.getPageNumber()
    canvas.setStrokeColor(COLORS["line"])
    canvas.setLineWidth(0.4)
    canvas.line(MARGIN_X, PAGE_HEIGHT - 12 * mm, PAGE_WIDTH - MARGIN_X, PAGE_HEIGHT - 12 * mm)
    if page > 1:
        if LOGO.exists():
            canvas.drawImage(str(LOGO), MARGIN_X, PAGE_HEIGHT - 10.8 * mm, width=7.5 * mm, height=7.5 * mm, preserveAspectRatio=True, mask='auto')
        canvas.setFont("Helvetica-Bold", 8)
        canvas.setFillColor(COLORS["navy"])
        canvas.drawString(MARGIN_X + 9 * mm, PAGE_HEIGHT - 8.5 * mm, "TechCalendar - Intégration Global+")
    canvas.setStrokeColor(COLORS["line"])
    canvas.line(MARGIN_X, 11 * mm, PAGE_WIDTH - MARGIN_X, 11 * mm)
    canvas.setFont("Helvetica", 7.4)
    canvas.setFillColor(COLORS["muted"])
    canvas.drawCentredString(PAGE_WIDTH / 2, 6.7 * mm, f"Rapport d'intégration API - Page {page}")
    canvas.restoreState()


def build_pdf():
    doc = BaseDocTemplate(
        str(OUTPUT),
        pagesize=A4,
        leftMargin=MARGIN_X,
        rightMargin=MARGIN_X,
        topMargin=MARGIN_TOP,
        bottomMargin=MARGIN_BOTTOM,
        title="Rapport d'intégration TechCalendar vers Global+",
        author="TechCalendar",
    )
    frame = Frame(MARGIN_X, MARGIN_BOTTOM, PAGE_WIDTH - 2 * MARGIN_X, PAGE_HEIGHT - MARGIN_TOP - MARGIN_BOTTOM, id="normal")
    doc.addPageTemplates([PageTemplate(id="default", frames=[frame], onPage=draw_header_footer)])

    story = []

    story.append(Spacer(1, 14 * mm))
    if LOGO.exists():
        story.append(Image(str(LOGO), width=24 * mm, height=24 * mm))
        story.append(Spacer(1, 7 * mm))
    story.append(p("Rapport d'intégration API", "CoverTitle"))
    story.append(p("TechCalendar vers Global+", "CoverTitle"))
    story.append(p("Création et mise à jour de dossiers physiques issus de lots, avec transmission des données client, bénéficiaire, installateur, technicien, rendez-vous et documents.", "CoverSubtitle"))
    story.append(Spacer(1, 8 * mm))
    story.append(callout("Objectif", "Permettre à Global+ d'évaluer précisément les endpoints à exposer, les référentiels à partager et le format des données nécessaires pour connecter TechCalendar à leur interface de création de dossier."))
    story.append(Spacer(1, 11 * mm))
    story.append(data_table(
        ["Document", "Version", "Date", "Contact technique"],
        [["Rapport d'intégration API", "1.1", "20/08/2026", "Équipe TechCalendar"]],
        [48 * mm, 25 * mm, 32 * mm, PAGE_WIDTH - 2 * MARGIN_X - 105 * mm],
    ))
    story.append(PageBreak())

    story += section_title("1. Contexte et objectif")
    story.append(p("TechCalendar est l'outil interne utilisé pour importer des lots, qualifier les dossiers, planifier les visites physiques et suivre les interventions techniciens. Global+ sera utilisé comme application métier destinataire pour certains dossiers physiques issus de lots."))
    story.append(p("Dans TechCalendar, l'utilisateur sera sur le détail d'un rendez-vous physique déjà placé. Un bouton <b>Créer à Global+</b> ouvrira une fenêtre de préparation avant envoi. Cette fenêtre préremplira les champs disponibles depuis TechCalendar et laissera l'utilisateur compléter ou corriger les champs qui dépendent des référentiels Global+."))
    story.append(callout("Règle métier", "TechCalendar ne doit pas considérer le dossier comme créé dans Global+ tant que l'API Global+ n'a pas confirmé la création distante et retourné un identifiant exploitable."))
    story.append(p("Périmètre prioritaire", "H2Custom"))
    story.append(bullets([
        "Créer un dossier Global+ depuis un rendez-vous physique issu d'un lot TechCalendar.",
        "Préremplir les champs Global+ depuis les données déjà importées dans TechCalendar.",
        "Permettre la sélection d'un installateur et d'un technicien depuis les référentiels Global+.",
        "Transmettre les documents du dossier, avec option de les envoyer ou non lors de la création.",
        "Prévoir les routes de mise à jour si le dossier Global+ est modifié après création depuis TechCalendar.",
        "Éviter les doublons grâce à une clé d'idempotence côté création.",
    ]))

    story += section_title("2. Parcours utilisateur attendu")
    story.append(data_table(
        ["Étape", "Action", "Comportement attendu"],
        [
            ["1", "Ouverture du détail d'un rendez-vous physique issu d'un lot.", "TechCalendar affiche les données du dossier, le rendez-vous, les documents et le bouton Créer à Global+."],
            ["2", "Clic sur Créer à Global+.", "Ouverture d'un formulaire de préparation avec champs préremplis et champs à compléter."],
            ["3", "Chargement des référentiels Global+.", "TechCalendar récupère en direct les installateurs, techniciens et idéalement les valeurs de précarité/prestation si Global+ les impose."],
            ["4", "Validation utilisateur.", "TechCalendar envoie le dossier, le rendez-vous et éventuellement les documents à Global+."],
            ["5", "Réponse Global+.", "TechCalendar stocke les identifiants Global+ et affiche le retour de création ou les erreurs métier."],
            ["6", "Modification ultérieure.", "Si le rendez-vous, le technicien, l'adresse ou les documents changent, TechCalendar appelle les routes d'update Global+."],
        ],
        [16 * mm, 63 * mm, PAGE_WIDTH - 2 * MARGIN_X - 79 * mm],
    ))
    story.append(PageBreak())

    story += section_title("3. Mapping des champs de création")
    story.append(p("Le tableau ci-dessous reprend les champs observés dans l'interface Global+ et propose le comportement souhaité côté TechCalendar. Les champs préremplis restent modifiables avant envoi, afin de gérer les corrections de dernière minute."))
    story.append(data_table(
        ["Bloc Global+", "Champ", "Source TechCalendar", "Comportement"],
        [
            ["Lieu d'intervention", "Nom / prénom", "Nom du site, nom client, ou bénéficiaire selon données disponibles.", "Prérempli, modifiable."],
            ["Lieu d'intervention", "Bâtiment", "Nom du site ou champ site_name importé depuis le lot.", "Prérempli si disponible, sinon manuel."],
            ["Lieu d'intervention", "Téléphone", "customer_phone du dossier de lot.", "Prérempli, modifiable."],
            ["Lieu d'intervention", "Adresse, code postal, ville", "address, postal_code, city du dossier de lot ou adresse corrigée dans TechCalendar.", "Prérempli, modifiable."],
            ["Lieu d'intervention", "Précarité", "Non disponible aujourd'hui dans TechCalendar.", "Champ manuel ou liste Global+ à récupérer via référentiel."],
            ["Bénéficiaire", "Nom / prénom", "company_name, customer_name, customer_first_name, customer_last_name.", "Prérempli avec le bénéficiaire importé. Peut être identique au lieu d'intervention."],
            ["Bénéficiaire", "Bâtiment", "site_name si pertinent.", "Prérempli si disponible, sinon manuel."],
            ["Bénéficiaire", "Téléphone", "customer_phone.", "Prérempli, modifiable."],
            ["Bénéficiaire", "Adresse, code postal, ville", "Même adresse que le client par défaut.", "Prérempli par recopie, modifiable séparément."],
            ["Entreprise travaux", "Installateur", "installer_name importé dans le lot.", "Matching avec la liste Global+. Sélection obligatoire si Global+ impose un ID."],
            ["Entreprise travaux", "Nom, raison sociale, SIREN, adresse, code postal, ville", "Champs manuels si l'installateur n'est pas trouvé ou si Global+ autorise la saisie libre.", "À afficher en secours, ou pour aide au matching."],
            ["Options dossier", "Numéro du lot", "lot.id, nom du lot, référence interne.", "Prérempli."],
            ["Options dossier", "Sous-titre", "Nom du lot plus détail dossier: client, site ou ligne importée.", "Prérempli, modifiable."],
            ["Options dossier", "Transmettre les documents", "Documents associés au dossier TechCalendar.", "Checkbox activée par défaut, modifiable."],
            ["Rendez-vous", "Date et heure", "appointment.starts_at et ends_at.", "Prérempli si RDV placé, sinon envoyé plus tard via update."],
            ["Rendez-vous", "Technicien", "appointment.technician.email.", "Matching avec la base techniciens Global+. Sélection ou confirmation obligatoire."],
        ],
        [32 * mm, 37 * mm, 72 * mm, PAGE_WIDTH - 2 * MARGIN_X - 141 * mm],
    ))
    story.append(PageBreak())

    story += section_title("4. Référentiels et matching nécessaires")
    story.append(p("Pour éviter une saisie fragile et limiter les erreurs, TechCalendar doit pouvoir récupérer certains référentiels Global+ en direct au moment de la création. Le matching ne doit pas dépendre uniquement de libellés libres."))
    story.append(data_table(
        ["Référentiel", "Besoin", "Clé de matching proposée"],
        [
            ["Installateurs", "Récupérer la liste des entreprises travaux existantes dans Global+.", "Raison sociale normalisée, SIREN si disponible. Retour attendu: id, nom, raison sociale, SIREN, adresse, CP, ville."],
            ["Techniciens", "Récupérer les techniciens Global+ enregistrés et actifs.", "Email en priorité, sinon nom/prénom en secours. Retour attendu: id, email, prénom, nom, statut."],
            ["Précarité", "Comprendre le type attendu par Global+: booléen, enum, classe métier ou valeur libre.", "Référentiel ou liste de valeurs. TechCalendar ne stocke pas encore ce champ aujourd'hui."],
            ["Prestations", "Confirmer si Global+ attend un code, un alias ou un ID interne.", "service_alias défini dans TechCalendar, avec sélection manuelle possible si plusieurs alias."],
        ],
        [33 * mm, 67 * mm, PAGE_WIDTH - 2 * MARGIN_X - 100 * mm],
    ))
    story.append(Spacer(1, 5 * mm))
    story.append(callout("Point d'attention", "Si Global+ exige des IDs internes pour l'installateur, le technicien, la précarité ou la prestation, il faudra exposer les endpoints de liste ou de recherche correspondants. TechCalendar stockera alors l'ID Global+ sélectionné pour les mises à jour futures."))

    story += section_title("5. Gestion des documents")
    story.append(p("Les dossiers issus de lots peuvent contenir plusieurs documents. Dans le formulaire Créer à Global+, l'utilisateur doit pouvoir choisir de transmettre les documents du dossier. Les documents doivent ensuite être visibles dans Global+."))
    story.append(data_table(
        ["Option", "Description", "Point d'attention"],
        [
            ["Envoi lors de la création", "Le payload de création inclut ou déclenche l'envoi des documents du dossier.", "Pratique pour l'utilisateur, mais potentiellement plus long si le volume est important."],
            ["Envoi après création", "TechCalendar crée le dossier puis envoie chaque document sur un endpoint dédié.", "Plus robuste: permet de relancer uniquement les documents échoués."],
            ["URL temporaire", "TechCalendar fournit une URL signée et Global+ télécharge le fichier.", "Adapté aux fichiers lourds ou aux traitements asynchrones."],
        ],
        [39 * mm, 78 * mm, PAGE_WIDTH - 2 * MARGIN_X - 117 * mm],
    ))
    story.append(p("Chaque document peut porter un nom modifiable et, si Global+ le supporte, un booléen privé/public."))
    story.append(PageBreak())

    story += section_title("6. Endpoints souhaités")
    story.append(data_table(
        ["Endpoint", "Méthode", "But"],
        [
            ["/api/techcalendar/global-plus/installers", "GET", "Lister ou rechercher les installateurs disponibles dans Global+."],
            ["/api/techcalendar/global-plus/technicians", "GET", "Lister ou rechercher les techniciens actifs Global+."],
            ["/api/techcalendar/global-plus/services", "GET", "Lister les prestations, codes et alias acceptés."],
            ["/api/techcalendar/global-plus/precarities", "GET", "Lister les valeurs de précarité si ce champ est obligatoire."],
            ["/api/techcalendar/global-plus/dossiers", "POST", "Créer un dossier depuis TechCalendar."],
            ["/api/techcalendar/global-plus/dossiers/{id}", "PATCH", "Mettre à jour un dossier déjà créé: adresse, client, bénéficiaire, installateur, options."],
            ["/api/techcalendar/global-plus/dossiers/{id}/appointment", "PUT/PATCH", "Créer ou mettre à jour la date, l'heure et le technicien du rendez-vous."],
            ["/api/techcalendar/global-plus/dossiers/{id}/documents", "POST", "Ajouter un document au dossier Global+."],
            ["/api/techcalendar/global-plus/dossiers/{id}", "GET", "Récupérer l'état courant du dossier Global+."],
        ],
        [78 * mm, 24 * mm, PAGE_WIDTH - 2 * MARGIN_X - 102 * mm],
    ))
    story.append(Spacer(1, 5 * mm))
    story.append(callout("Mise à jour obligatoire", "Si un dossier est créé dans Global+ avant que le rendez-vous soit définitivement placé, TechCalendar doit pouvoir envoyer ultérieurement le technicien, la date et l'heure via une route d'update."))

    story.append(PageBreak())
    story += section_title("7. Exemple de payload de création")
    story.append(code_block(r'''
{
  "external_source": "techcalendar",
  "external_lot_id": 40,
  "external_lot_appointment_id": 573,
  "idempotency_key": "techcalendar-lot-appointment-573-global-plus",
  "service": {
    "name": "BAR EN 101",
    "alias": "BAR EN 101 Isolation en combles perdus",
    "global_plus_service_id": "optional-if-known"
  },
  "intervention_site": {
    "first_name": null,
    "last_name": null,
    "name": "Nom du site ou client",
    "building": "Bâtiment A",
    "phone": "+33600000000",
    "address": "10 rue Exemple",
    "postal_code": "75001",
    "city": "Paris",
    "precarity": "value-selected-from-global-plus"
  },
  "beneficiary": {
    "same_as_intervention_site": true,
    "name": "RAISON SOCIALE BENEFICIAIRE",
    "building": "Bâtiment A",
    "phone": "+33600000000",
    "address": "10 rue Exemple",
    "postal_code": "75001",
    "city": "Paris"
  },
  "installer": {
    "global_plus_installer_id": "123",
    "company_name": "RAISON SOCIALE INSTALLATEUR",
    "siren": "123456789"
  },
  "lot_options": {
    "lot_number": "40",
    "subtitle": "P5 - 104 Alvea - ligne 573",
    "send_documents": true
  },
  "appointment": {
    "starts_at": "2026-08-20T09:30:00+02:00",
    "ends_at": "2026-08-20T10:30:00+02:00",
    "technician": {
      "global_plus_technician_id": "456",
      "email": "technicien@example.com"
    }
  }
}
'''))
    story.append(PageBreak())

    story += section_title("8. Exemple de payload de mise à jour")
    story.append(p("La mise à jour doit permettre de corriger un dossier Global+ déjà créé, notamment si l'adresse est modifiée, si le rendez-vous est déplacé ou si le technicien change après la création initiale."))
    story.append(code_block(r'''
PATCH /api/techcalendar/global-plus/dossiers/{global_plus_dossier_id}

{
  "intervention_site": {
    "address": "12 rue Corrigée",
    "postal_code": "75002",
    "city": "Paris"
  },
  "beneficiary": {
    "name": "RAISON SOCIALE BENEFICIAIRE CORRIGEE"
  },
  "installer": {
    "global_plus_installer_id": "123"
  },
  "lot_options": {
    "subtitle": "P5 - 104 Alvea - dossier corrigé"
  }
}

PATCH /api/techcalendar/global-plus/dossiers/{global_plus_dossier_id}/appointment

{
  "starts_at": "2026-08-22T14:00:00+02:00",
  "ends_at": "2026-08-22T15:00:00+02:00",
  "technician": {
    "global_plus_technician_id": "456",
    "email": "technicien@example.com"
  }
}
'''))

    story += section_title("9. Réponses attendues et erreurs")
    story.append(p("Global+ doit retourner des réponses JSON structurées, lisibles et stables, afin que TechCalendar puisse afficher une erreur métier claire à l'utilisateur et éviter les faux positifs."))
    story.append(data_table(
        ["Cas", "Réponse attendue"],
        [
            ["Création réussie", "success=true, global_plus_dossier_id, status, message, documents importés."],
            ["Installateur introuvable", "Erreur métier avec code INSTALLER_NOT_FOUND ou besoin de sélection manuelle."],
            ["Technicien introuvable", "Erreur métier avec code TECHNICIAN_NOT_FOUND et possibilité de sélectionner un autre technicien."],
            ["Précarité manquante", "Erreur métier indiquant si le champ est obligatoire et quelles valeurs sont acceptées."],
            ["Document échoué", "Retour par document: external_document_id, status, message."],
            ["Doublon", "Grâce à l'idempotency_key, retourner le dossier existant plutôt que créer un doublon."],
        ],
        [42 * mm, PAGE_WIDTH - 2 * MARGIN_X - 42 * mm],
    ))
    story.append(PageBreak())

    story += section_title("10. Questions pour Global+")
    story.append(bullets([
        "Quels endpoints exacts existent déjà pour créer un rendez-vous ou dossier ?",
        "Le dossier Global+ peut-il être créé avant la date de rendez-vous, puis mis à jour plus tard ?",
        "Quels champs sont strictement obligatoires: précarité, prestation, installateur, technicien, documents ?",
        "Pouvez-vous exposer un endpoint de recherche/liste des installateurs avec ID Global+ ?",
        "Pouvez-vous exposer un endpoint de recherche/liste des techniciens avec ID Global+ et email ?",
        "Comment Global+ attend-il le champ précarité: booléen, enum, ID de référentiel ou texte libre ?",
        "La prestation doit-elle être transmise par libellé, alias, code ou ID interne ?",
        "Quel format acceptez-vous pour les documents: multipart, base64, URL temporaire ?",
        "Gérez-vous l'idempotence côté API pour éviter les doublons ?",
        "Quels codes d'erreur métier pouvez-vous retourner ?",
        "Existe-t-il un environnement sandbox pour tester les créations et mises à jour ?",
    ]))
    story.append(Spacer(1, 4 * mm))
    story.append(callout("Conclusion", "TechCalendar doit agir comme outil de préparation et de planification, puis pousser vers Global+ un dossier complet et contrôlé. L'intégration doit donc couvrir la création, le matching des référentiels, l'envoi des documents et les mises à jour ultérieures du dossier ou du rendez-vous."))

    doc.build(story)


if __name__ == "__main__":
    build_pdf()
    print(OUTPUT)
