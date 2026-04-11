<script setup>
/**
 * StoryContextPanel — right pane with collapsible sections
 * for entities, notes, arc, and AI assistance.
 */
import { ref } from 'vue'
import StoryEntityList from './StoryEntityList.vue'
import StoryNoteList from './StoryNoteList.vue'
import StoryArcRef from './StoryArcRef.vue'
import StoryAiPanel from './StoryAiPanel.vue'

const props = defineProps({
  worldId:  { type: [Number, String], required: true },
  storyId:  { type: [Number, String], required: true },
  story:    { type: Object, required: true },
})

const emit = defineEmits(['refresh'])

// Collapsible section state
const sections = ref({
  entities: true,
  notes:    true,
  arc:      true,
  ai:       false,
})

function toggle(key) {
  sections.value[key] = !sections.value[key]
}
</script>

<template>
  <div class="story-context">
    <!-- Entities section -->
    <section class="story-context__section">
      <button class="story-context__heading" @click="toggle('entities')">
        <span>Entities</span>
        <span class="story-context__chevron">{{ sections.entities ? '▾' : '▸' }}</span>
      </button>
      <div v-if="sections.entities" class="story-context__body">
        <StoryEntityList
          :world-id="worldId"
          :story-id="storyId"
          :entities="story.entities ?? []"
          @refresh="emit('refresh')"
        />
      </div>
    </section>

    <!-- Notes section -->
    <section class="story-context__section">
      <button class="story-context__heading" @click="toggle('notes')">
        <span>Notes</span>
        <span class="story-context__chevron">{{ sections.notes ? '▾' : '▸' }}</span>
      </button>
      <div v-if="sections.notes" class="story-context__body">
        <StoryNoteList
          :world-id="worldId"
          :story-id="storyId"
          :notes="story.notes ?? []"
          @refresh="emit('refresh')"
        />
      </div>
    </section>

    <!-- Arc section -->
    <section class="story-context__section">
      <button class="story-context__heading" @click="toggle('arc')">
        <span>Story Arc</span>
        <span class="story-context__chevron">{{ sections.arc ? '▾' : '▸' }}</span>
      </button>
      <div v-if="sections.arc" class="story-context__body">
        <StoryArcRef
          :world-id="worldId"
          :story="story"
        />
      </div>
    </section>

    <!-- AI section -->
    <section class="story-context__section">
      <button class="story-context__heading" @click="toggle('ai')">
        <span>AI Assistant</span>
        <span class="story-context__chevron">{{ sections.ai ? '▾' : '▸' }}</span>
      </button>
      <div v-if="sections.ai" class="story-context__body">
        <StoryAiPanel
          :world-id="worldId"
          :story-id="storyId"
          :story="story"
        />
      </div>
    </section>
  </div>
</template>
