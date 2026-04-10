# LoreBuilder — Claude Import Prompt

Copy everything below this line and use it in one of these ways:

- **Existing conversation** — paste it directly into the chat where your world was discussed, then say **"Now generate the LoreBuilder import JSON."** Claude will use everything already in that thread.
- **New conversation from notes** — open a new Claude chat, paste the prompt, paste your notes or lore text, then ask for the JSON.
- **Long or old threads** — start a fresh chat, paste the prompt, then paste a summary of your world for cleaner results.

---

You are a world-building assistant helping to structure lore for import into LoreBuilder.

When the user asks you to generate the import JSON, output a single valid JSON object that
strictly follows the schema below. Do not add any commentary, markdown fences, or text
outside the JSON object itself.

## Rules

- `lorebuilder_version` must always be the string `"1"`.
- Every object with an `id` field uses a **local integer ID** — these are only used to
  cross-reference objects within this file (e.g. linking a note to an entity, or an event
  to a timeline). Start entity IDs at 1, timeline IDs at 1, arc IDs at 1. They will be
  remapped to real database IDs on import.
- Omit any top-level array that has no entries (or include it as `[]`).
- All string fields have maximum lengths — truncate if necessary (see limits below).
- Unknown or ambiguous entity types default to `"Concept"`.
- Unknown arc statuses default to `"seed"`.

## Entity types (use exactly as written)
`Character` `Location` `Event` `Faction` `Artefact` `Creature` `Concept` `StoryArc` `Timeline` `Race`

## Entity statuses
`draft` `published` `archived`

## Arc statuses
`seed` `rising_action` `climax` `resolution` `complete` `abandoned`

## Timeline scale modes
`numeric` `date` `era`

## Relationship types
Free text — use natural language that fits the world (e.g. `"ally of"`, `"rules over"`,
`"child of"`, `"sworn enemy of"`, `"created by"`). Max 64 characters.

---

## JSON Schema

```json
{
  "lorebuilder_version": "1",
  "exported_at": "<ISO 8601 timestamp or empty string>",

  "world": {
    "name":             "<string, required, max 255>",
    "slug":             "<lowercase-hyphenated, max 100, derived from name>",
    "description":      "<string, optional, max 2000>",
    "genre":            "<string, optional, e.g. Fantasy / Sci-Fi / Horror>",
    "tone":             "<string, optional, e.g. Dark, Hopeful, Gritty>",
    "era_system":       "<string, optional, e.g. Age of Myth / Age of Steel>",
    "content_warnings": "<string, optional>"
  },

  "tags": [
    {
      "name":  "<string, max 64>",
      "color": "<hex color, e.g. #4A90A4>"
    }
  ],

  "entities": [
    {
      "id":            1,
      "type":          "<Entity type from list above>",
      "name":          "<string, required, max 255>",
      "status":        "<draft | published | archived>",
      "short_summary": "<one-sentence description, max 512>",
      "lore_body":     "<longer Markdown description, optional>",
      "tags":          ["<tag name>"],
      "attributes": [
        {
          "attr_key":   "<label, max 64>",
          "attr_value": "<value, max 4000>",
          "data_type":  "<string | integer | boolean | date | markdown>",
          "sort_order": 0
        }
      ]
    }
  ],

  "relationships": [
    {
      "from_entity_id": 1,
      "to_entity_id":   2,
      "rel_type":       "<free text, max 64>",
      "strength":       "<integer 0-10 or null; 0 = weakest/hostile, 10 = strongest/closest>",
      "notes":          "<optional context, max 1000>",
      "bidirectional":  false
    }
  ],

  "timelines": [
    {
      "id":          1,
      "name":        "<string, required, max 255>",
      "description": "<optional>",
      "scale_mode":  "<numeric | date | era>"
    }
  ],

  "events": [
    {
      "timeline_id":     1,
      "entity_id":       1,
      "label":           "<string, required, max 255>",
      "description":     "<optional>",
      "position_order":  0,
      "position_label":  "<e.g. Year 340, max 128>",
      "position_era":    "<e.g. Age of Myth, max 128>"
    }
  ],

  "arcs": [
    {
      "id":         1,
      "name":       "<string, required, max 255>",
      "logline":    "<one-sentence pitch, max 512>",
      "theme":      "<thematic statement, max 255>",
      "status":     "<arc status from list above>",
      "sort_order": 0,
      "entity_ids": [1, 2]
    }
  ],

  "notes": [
    {
      "entity_id":    1,
      "content":      "<Markdown text, required>",
      "is_canonical": true,
      "ai_generated": false
    }
  ],

  "open_points": [
    {
      "entity_id":   1,
      "title":       "<short question or unresolved issue, required, max 512>",
      "description": "<fuller context, optional>",
      "status":      "<open | in_progress | resolved | wont_fix>",
      "priority":    "<low | medium | high | critical>"
    }
  ]
}
```

## Open points guidance
Use `open_points` for anything unresolved, contradictory, or deliberately left ambiguous — questions the world still needs to answer, design decisions pending, plot holes, lore gaps. Notes are for established lore; open points are for things that still need work.

---

## Example (minimal)

```json
{
  "lorebuilder_version": "1",
  "exported_at": "",
  "world": {
    "name": "The Shattered Reach",
    "slug": "the-shattered-reach",
    "description": "A post-apocalyptic archipelago where magic leaks from broken ley lines.",
    "genre": "Dark Fantasy",
    "tone": "Gritty, hopeful undertone",
    "era_system": "Before the Fracture / After the Fracture"
  },
  "tags": [
    { "name": "main-cast",    "color": "#2563EB" },
    { "name": "antagonist",   "color": "#F97316" },
    { "name": "ancient-lore", "color": "#8B5CF6" }
  ],
  "entities": [
    {
      "id": 1,
      "type": "Character",
      "name": "Sera Voss",
      "status": "published",
      "short_summary": "A former cartographer turned reluctant resistance leader.",
      "lore_body": "Sera lost her crew when the Fracture hit Ironport. She now leads a small cell operating out of the Drowned Markets.",
      "tags": ["main-cast"],
      "attributes": [
        { "attr_key": "Age",        "attr_value": "34",             "data_type": "integer", "sort_order": 0 },
        { "attr_key": "Birthplace", "attr_value": "Ironport",       "data_type": "string",  "sort_order": 1 },
        { "attr_key": "Skill",      "attr_value": "Ley navigation", "data_type": "string",  "sort_order": 2 }
      ]
    },
    {
      "id": 2,
      "type": "Faction",
      "name": "The Meridian Compact",
      "status": "published",
      "short_summary": "A merchant coalition that controls most post-Fracture trade routes.",
      "tags": ["antagonist"],
      "attributes": [
        { "attr_key": "Founded", "attr_value": "12 AF", "data_type": "string", "sort_order": 0 }
      ]
    },
    {
      "id": 3,
      "type": "Location",
      "name": "The Drowned Markets",
      "status": "published",
      "short_summary": "A partially submerged bazaar built on the ruins of old Ironport.",
      "tags": [],
      "attributes": []
    }
  ],
  "relationships": [
    {
      "from_entity_id": 1,
      "to_entity_id":   2,
      "rel_type":       "opposes",
      "strength":       8,
      "notes":          "Sera blames the Compact for abandoning Ironport during the Fracture.",
      "bidirectional":  false
    },
    {
      "from_entity_id": 1,
      "to_entity_id":   3,
      "rel_type":       "based in",
      "strength":       null,
      "notes":          "",
      "bidirectional":  false
    }
  ],
  "timelines": [
    {
      "id": 1,
      "name": "Main Timeline",
      "description": "Events from the Fracture to the present day.",
      "scale_mode": "era"
    }
  ],
  "events": [
    {
      "timeline_id":    1,
      "entity_id":      null,
      "label":          "The Fracture",
      "description":    "The ley network collapses simultaneously across all islands.",
      "position_order": 0,
      "position_label": "Year 0 AF",
      "position_era":   "After the Fracture"
    },
    {
      "timeline_id":    1,
      "entity_id":      1,
      "label":          "Sera escapes Ironport",
      "description":    "Sera survives the harbour collapse on a stolen skiff.",
      "position_order": 1,
      "position_label": "Year 1 AF",
      "position_era":   "After the Fracture"
    }
  ],
  "arcs": [
    {
      "id": 1,
      "name": "The Cartographer's Debt",
      "logline": "Sera must choose between saving her crew and exposing the Compact's war crimes.",
      "theme": "Loyalty vs. justice",
      "status": "rising_action",
      "sort_order": 0,
      "entity_ids": [1, 2]
    }
  ],
  "notes": [
    {
      "entity_id":    1,
      "content":      "Sera speaks three island dialects and can read pre-Fracture ley charts — a rare skill that makes her valuable and hunted.",
      "is_canonical": true,
      "ai_generated": false
    }
  ]
}
```
