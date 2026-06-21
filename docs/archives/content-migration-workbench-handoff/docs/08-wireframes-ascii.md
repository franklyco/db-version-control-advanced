# 08 — Wireframes (ASCII)

## Main workbench

```text
┌───────────────────────────────────────────────────────────────────────────────────────────────┐
│ Run: CM-V2-042  |  Page 12/88  |  URL: /services/general-dentistry  |  Target: service/post │
│ Status: Needs Review  |  Hero Ready  |  Reviews Warning  |  Save  |  Save+Next             │
├───────────────────────┬───────────────────────────────────────┬───────────────────────────────┤
│ Source Evidence       │ Target Page Workbench                │ Inspector                     │
│────────────────────── │────────────────────────────────────── │────────────────────────────── │
│ [search blocks...]    │ Template: Service Page               │ Selected: Hero Title          │
│                       │                                       │                               │
│ OUTLINE               │ HERO                                  │ Recommendation                │
│ - Hero                │ ┌───────────────────────────────────┐ │ - Top match: Hero Title       │
│ - Intro               │ │ Title   "Comprehensive..." [High]│ │ - Confidence: High           │
│ - Reviews             │ │ Body    "Personalized care..."   │ │ - Why: H1 + hero cluster     │
│ - FAQ                 │ │ CTA     "Schedule Now"           │ │                               │
│ - CTA                 │ │ Image   [unmapped]               │ │ Alternatives                  │
│                       │ └───────────────────────────────────┘ │ 1. Intro Heading              │
│ BLOCKS                │                                       │ 2. CTA Title                  │
│ [H] "Comprehensive..."│ INTRO                                 │                               │
│ [P] "Personalized..." │ ┌───────────────────────────────────┐ │ Transform Preview             │
│ [Q] "Do you accept..."│ │ Heading "A Modern Approach..."   │ │ source -> normalized -> final│
│ [A] "Yes, we..."      │ │ Body    "We combine..."          │ │                               │
│ [T] "Wonderful team.."│ └───────────────────────────────────┘ │ Actions                       │
│                       │                                       │ [Accept] [Reassign] [Unresolve]│
│ UNMATCHED (3)         │ REVIEWS                               │                               │
│ - promo line          │ ┌───────────────────────────────────┐ │                               │
│ - orphan image        │ │ Row 1: "Wonderful..." / Sarah K. │ │                               │
│ - badge text          │ │ Row 2: "Best visit..." / ?       │ │                               │
├───────────────────────────────────────────────────────────────────────────────────────────────┤
│ Dock: [Unmatched] [Warnings] [Conflicts] [Activity] [Batch] [Shortcuts]                     │
└───────────────────────────────────────────────────────────────────────────────────────────────┘
```

## Repeater row mode

```text
REVIEWS
┌────────────────────────────────────────────────────────────────┐
│ Row 1                                                         │
│ Quote: "Wonderful team and very gentle care."                 │
│ Author: Sarah K.                                              │
│ Rating: 5                                                     │
│ Status: Recommended / High                                    │
│ Actions: Accept | Reassign | Remove                           │
├────────────────────────────────────────────────────────────────┤
│ Row 2                                                         │
│ Quote: "Best dental visit I've had in years."                 │
│ Author: unresolved                                            │
│ Rating: unresolved                                            │
│ Status: Warning                                               │
│ Actions: Merge | Search source | Unresolve                    │
└────────────────────────────────────────────────────────────────┘
```

## Manual field picker

```text
┌────────────────────────────────────────────────┐
│ Reassign target field                          │
│ [ search: hero title ]                         │
├────────────────────────────────────────────────┤
│ Hero > Title                         VALID     │
│ Intro > Heading                      VALID     │
│ CTA > Title                          VALID     │
│ Reviews > Quote                      INVALID   │
│   reason: incompatible field type              │
└────────────────────────────────────────────────┘
```

## Bottom dock — unmatched

```text
UNMATCHED SOURCE
┌────────────────────────────────────────────────────────────────┐
│ "Call today to transform your smile."                         │
│ guessed section: CTA                                          │
│ possible uses: CTA body, CTA title                            │
│ actions: assign | keep unresolved | discard as noise          │
├────────────────────────────────────────────────────────────────┤
│ image: waiting-room.jpg                                       │
│ guessed section: Hero / Gallery                               │
│ actions: assign to image slot | keep unresolved               │
└────────────────────────────────────────────────────────────────┘
```
