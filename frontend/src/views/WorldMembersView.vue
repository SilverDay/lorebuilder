<script setup>
import { ref, onMounted } from 'vue'
import { useRoute }       from 'vue-router'
import { useAuthStore }   from '@/stores/auth.js'
import { api }            from '@/api/client.js'

const route = useRoute()
const auth  = useAuthStore()
const wid   = route.params.wid

const members     = ref([])
const loading     = ref(true)
const error       = ref('')

// Invite form
const inviteEmail = ref('')
const inviteRole  = ref('author')
const inviting    = ref(false)
const inviteMsg   = ref('')
const inviteErr   = ref('')

const ROLES = ['owner','admin','author','reviewer','viewer']

async function load() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await api.get(`/api/v1/worlds/${wid}/members`)
    members.value = data ?? []
  } catch (e) {
    error.value = e.message || 'Failed to load members.'
  } finally {
    loading.value = false
  }
}

async function changeRole(member, newRole) {
  if (member.role === newRole) return
  try {
    await api.patch(`/api/v1/worlds/${wid}/members/${member.user_id}`, { role: newRole })
    member.role = newRole
  } catch (e) {
    error.value = e.message || 'Failed to update role.'
  }
}

async function removeMember(member) {
  if (!confirm(`Remove ${member.display_name} from this world?`)) return
  try {
    await api.delete(`/api/v1/worlds/${wid}/members/${member.user_id}`)
    members.value = members.value.filter(m => m.user_id !== member.user_id)
  } catch (e) {
    error.value = e.message || 'Failed to remove member.'
  }
}

async function sendInvite() {
  inviting.value = true
  inviteErr.value = ''
  inviteMsg.value = ''
  try {
    await api.post(`/api/v1/worlds/${wid}/invitations`, {
      email: inviteEmail.value.trim(),
      role:  inviteRole.value,
    })
    inviteMsg.value = `Invitation sent to ${inviteEmail.value.trim()}.`
    inviteEmail.value = ''
  } catch (e) {
    inviteErr.value = e.message || 'Failed to send invitation.'
  } finally {
    inviting.value = false
  }
}

// Can the current user edit this member's role?
function canEdit(member) {
  const myRole = auth.user?.role ?? 'viewer'
  if (member.role === 'owner') return false          // owner is immutable
  if (myRole === 'owner') return true
  if (myRole === 'admin' && member.role !== 'admin') return true
  return false
}

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="page-header">
      <h1>Members</h1>
    </header>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <p v-if="loading" class="loading">Loading…</p>

    <template v-else>
      <!-- Member table -->
      <table class="audit-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Joined</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in members" :key="m.user_id">
            <td>
              <strong>{{ m.display_name }}</strong>
              <span class="muted"> @{{ m.username }}</span>
            </td>
            <td>
              <select
                v-if="canEdit(m)"
                :value="m.role"
                class="role-select"
                @change="changeRole(m, $event.target.value)"
              >
                <option v-for="r in ROLES" :key="r" :value="r">{{ r }}</option>
              </select>
              <span v-else class="badge badge-role">{{ m.role }}</span>
            </td>
            <td class="muted">{{ m.joined_at ? new Date(m.joined_at).toLocaleDateString() : '—' }}</td>
            <td>
              <button
                v-if="canEdit(m)"
                class="btn btn-ghost btn-sm"
                @click="removeMember(m)"
              >
                Remove
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Invite form -->
      <section class="settings-section" style="margin-top:2rem">
        <h2>Invite by Email</h2>
        <form class="settings-form" @submit.prevent="sendInvite">
          <label>
            Email address
            <input v-model="inviteEmail" type="email" required :disabled="inviting" />
          </label>
          <label>
            Role
            <select v-model="inviteRole" :disabled="inviting">
              <option v-for="r in ['author','reviewer','viewer','admin']" :key="r" :value="r">{{ r }}</option>
            </select>
          </label>
          <p v-if="inviteErr" class="form-error" role="alert">{{ inviteErr }}</p>
          <p v-if="inviteMsg" class="form-success" role="status">{{ inviteMsg }}</p>
          <button type="submit" class="btn btn-primary" :disabled="inviting || !inviteEmail.trim()">
            {{ inviting ? 'Sending…' : 'Send Invitation' }}
          </button>
        </form>
      </section>
    </template>
  </div>
</template>
