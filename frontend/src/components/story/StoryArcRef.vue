<script setup>
/**
 * StoryArcRef — shows linked story arc info (read-only display).
 */

const props = defineProps({
  worldId: { type: [Number, String], required: true },
  story:   { type: Object, required: true },
})
</script>

<template>
  <div class="story-arc-ref">
    <template v-if="story.arc_id">
      <div class="story-arc-ref__info">
        <RouterLink :to="`/worlds/${worldId}/arcs`" class="story-arc-ref__name">
          {{ story.arc_name }}
        </RouterLink>
        <span v-if="story.arc_status" class="badge" :class="`badge-status-${story.arc_status}`">
          {{ story.arc_status }}
        </span>
      </div>
      <p v-if="story.arc_logline" class="story-arc-ref__logline">{{ story.arc_logline }}</p>
    </template>
    <p v-else class="empty-state-sm">No story arc linked. Edit story metadata to assign one.</p>
  </div>
</template>
