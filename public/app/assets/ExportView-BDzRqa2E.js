import{a as i,c as l,b as t,d as I,w as D,n as J,j as _,e as b,i as L,z as q,t as o,h as p,s as R,l as A,g as M,k as r,m as U}from"./main-3DGabGLS.js";const B={class:"page"},F={class:"page-header"},T={class:"settings-section"},V={key:0,class:"form-error",role:"alert"},$=["disabled"],P={class:"settings-section"},H={class:"import-prompt-actions"},G={key:0,class:"import-prompt-preview"},W={class:"settings-section"},Y=["disabled"],z={key:0,class:"form-error",role:"alert"},K={key:1,class:"form-success",role:"status"},Q={key:2,class:"import-stats"},X=["disabled"],k=`You are a world-building assistant helping to structure lore for import into LoreBuilder.

When the user asks you to generate the import JSON, output a single valid JSON object that
strictly follows the schema below. Do not add any commentary, markdown fences, or text
outside the JSON object itself.

## Rules

- \`lorebuilder_version\` must always be the string \`"1"\`.
- Every object with an \`id\` field uses a **local integer ID** — these are only used to
  cross-reference objects within this file (e.g. linking a note to an entity, or an event
  to a timeline). Start entity IDs at 1, timeline IDs at 1, arc IDs at 1. They will be
  remapped to real database IDs on import.
- Omit any top-level array that has no entries (or include it as \`[]\`).
- All string fields have maximum lengths — truncate if necessary (see limits below).
- Unknown or ambiguous entity types default to \`"Concept"\`.
- Unknown arc statuses default to \`"seed"\`.

## Entity types (use exactly as written)
\`Character\` \`Location\` \`Event\` \`Faction\` \`Artefact\` \`Creature\` \`Concept\` \`StoryArc\` \`Timeline\` \`Race\`

## Entity statuses
\`draft\` \`published\` \`archived\`

## Arc statuses
\`seed\` \`rising_action\` \`climax\` \`resolution\` \`complete\` \`abandoned\`

## Timeline scale modes
\`numeric\` \`date\` \`era\`

## Relationship types
Free text — use natural language that fits the world (e.g. \`"ally of"\`, \`"rules over"\`,
\`"child of"\`, \`"sworn enemy of"\`, \`"created by"\`). Max 64 characters.

---

## JSON Schema

\`\`\`json
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
      "strength":       null,
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
\`\`\`

Use \`open_points\` for anything unresolved, contradictory, or deliberately left ambiguous — questions the world still needs to answer, design decisions pending, plot holes, lore gaps. Notes are for established lore; open points are for things that still need work.
`,oe={__name:"ExportView",setup(Z){const w=A().params.wid,u=r("json"),m=r(!1),c=r(""),d=r(!1),g=r(null),v=r(""),h=r(""),a=r(null),x=r(!1),f=r(!1);async function S(){await navigator.clipboard.writeText(k),x.value=!0,setTimeout(()=>{x.value=!1},2500)}async function C(){m.value=!0,c.value="";try{const s=await fetch(`/api/v1/worlds/${w}/export?format=${u.value}`,{credentials:"same-origin"});if(!s.ok){const j=await s.json().catch(()=>({}));throw new Error(j.error||`Export failed (${s.status})`)}const e=await s.blob(),y=URL.createObjectURL(e),n=document.createElement("a"),O=u.value==="json"?"json":"md";n.href=y,n.download=`world-export.${O}`,n.click(),URL.revokeObjectURL(y)}catch(s){c.value=s.message||"Export failed."}finally{m.value=!1}}function E(s){g.value=s.target.files?.[0]??null}async function N(){if(g.value){d.value=!0,h.value="",v.value="",a.value=null;try{const s=await g.value.text(),{data:e}=await U.post(`/api/v1/worlds/${w}/import`,JSON.parse(s));v.value="Import complete.",a.value=e}catch(s){h.value=s.message||"Import failed."}finally{d.value=!1}}}return(s,e)=>{const y=M("RouterLink");return i(),l("div",B,[t("header",F,[e[3]||(e[3]=t("h1",null,"Export / Import",-1)),I(y,{to:`/worlds/${J(w)}`,class:"btn btn-ghost"},{default:D(()=>[...e[2]||(e[2]=[b("← Dashboard",-1)])]),_:1},8,["to"])]),t("section",T,[e[6]||(e[6]=t("h2",null,"Export World",-1)),e[7]||(e[7]=t("p",{class:"muted"},"Downloads a complete snapshot of all entities, relationships, timelines, arcs and notes.",-1)),t("form",{class:"settings-form",onSubmit:_(C,["prevent"])},[t("label",null,[e[5]||(e[5]=b(" Format ",-1)),L(t("select",{"onUpdate:modelValue":e[0]||(e[0]=n=>u.value=n)},[...e[4]||(e[4]=[t("option",{value:"json"},"JSON (LoreBuilder format — can be re-imported)",-1),t("option",{value:"markdown"},"Markdown (human-readable, one section per entity)",-1)])],512),[[q,u.value]])]),c.value?(i(),l("p",V,o(c.value),1)):p("",!0),t("button",{type:"submit",class:"btn btn-primary",disabled:m.value},o(m.value?"Preparing…":"Download Export"),9,$)],32)]),t("section",P,[e[8]||(e[8]=R('<h2>Generate import JSON with Claude</h2><p class="muted"> Have a world living in notes, docs, or an existing conversation? Copy the prompt below and use it in any of these ways: </p><ol class="import-steps"><li><strong>Existing conversation:</strong> paste the prompt directly into the chat where your world was discussed, then say <em>&quot;Now generate the LoreBuilder import JSON.&quot;</em> Claude will use everything already in that thread.</li><li><strong>New conversation from notes:</strong> open a new Claude chat, paste the prompt, paste your notes or lore text, then ask for the JSON.</li><li><strong>Long or old threads:</strong> start a fresh chat, paste the prompt, then paste a summary of your world — Claude produces cleaner results with a focused context.</li><li>Save Claude&#39;s JSON response as a <code>.json</code> file and import it in the section below.</li></ol>',3)),t("div",H,[t("button",{class:"btn btn-primary",onClick:S},o(x.value?"✓ Copied!":"Copy prompt to clipboard"),1),t("button",{class:"btn btn-ghost",onClick:e[1]||(e[1]=n=>f.value=!f.value)},o(f.value?"Hide prompt":"Preview prompt"),1)]),f.value?(i(),l("div",G,[t("pre",null,o(k))])):p("",!0)]),t("section",W,[e[11]||(e[11]=t("h2",null,"Import from JSON",-1)),e[12]||(e[12]=t("p",{class:"muted"}," Imports entities, relationships, timelines, arcs and notes from a LoreBuilder JSON export or a Claude-generated import file. Existing data is preserved — the import always adds new records. ",-1)),t("form",{class:"settings-form",onSubmit:_(N,["prevent"])},[t("label",null,[e[9]||(e[9]=b(" JSON export file ",-1)),t("input",{type:"file",accept:".json,application/json",onChange:E,disabled:d.value},null,40,Y)]),h.value?(i(),l("p",z,o(h.value),1)):p("",!0),v.value?(i(),l("p",K,o(v.value),1)):p("",!0),a.value?(i(),l("div",Q,[e[10]||(e[10]=t("strong",null,"Imported:",-1)),b(" "+o(a.value.entities)+" entities · "+o(a.value.relationships)+" relationships · "+o(a.value.notes)+" notes · "+o(a.value.open_points)+" open points · "+o(a.value.timelines)+" timelines · "+o(a.value.arcs)+" arcs · "+o(a.value.tags)+" tags ",1)])):p("",!0),t("button",{type:"submit",class:"btn btn-secondary",disabled:d.value||!g.value},o(d.value?"Importing…":"Import"),9,X)],32)])])}}};export{oe as default};
