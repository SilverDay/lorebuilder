<script setup>
/**
 * RelationshipList — grouped by type, linked to counterpart entities.
 */
import { computed } from 'vue'

const props = defineProps({
  relationships: { type: Array,  required: true },
  entityId:      { type: Number, required: true },
  worldId:       { type: [String, Number], required: true },
})

// Group relationships by rel_type
const grouped = computed(() => {
  const map = {}
  for (const rel of props.relationships) {
    if (!map[rel.rel_type]) map[rel.rel_type] = []
    map[rel.rel_type].push(rel)
  }
  return map
})

function counterpart(rel) {
  return rel.from_entity_id === props.entityId
    ? { id: rel.to_entity_id, name: rel.to_name, type: rel.to_type }
    : { id: rel.from_entity_id, name: rel.from_name, type: rel.from_type }
}
</script>

<template>
  <section>
    <h2>Relationships</h2>

    <div v-if="Object.keys(grouped).length" class="rel-groups">
      <div v-for="(rels, type) in grouped" :key="type" class="rel-group">
        <h4 class="rel-type">{{ type }}</h4>
        <ul>
          <li v-for="rel in rels" :key="rel.id">
            <RouterLink :to="`/worlds/${worldId}/entities/${counterpart(rel).id}`">
              {{ counterpart(rel).name }}
            </RouterLink>
            <span class="badge">{{ counterpart(rel).type }}</span>
            <span v-if="rel.strength" class="rel-strength">×{{ rel.strength }}</span>
            <span v-if="rel.bidirectional" class="badge badge-bi">↔</span>
          </li>
        </ul>
      </div>
    </div>

    <p v-else class="empty-state">No relationships yet.</p>
  </section>
</template>
