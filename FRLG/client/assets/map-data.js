window.FRLG_MAP_DATA = {
  startMapId: 'PalletTown_PlayersHouse_2F',
  maps: {
    PalletTown_PlayersHouse_2F: {
      id: 'PalletTown_PlayersHouse_2F',
      label: 'PalletTown_PlayersHouse_2F',
      mapType: 'MAP_TYPE_INDOOR',
      width: 13,
      height: 8,
      start: { x: 6, y: 4, dir: 'down' },
      blocked: [
        '0,0','1,0','2,0','3,0','4,0','5,0','6,0','7,0','8,0','9,0','10,0','11,0','12,0',
        '0,1','0,2','0,3','0,4','0,5','0,6','0,7',
        '12,1','12,2','12,3','12,4','12,5','12,6','12,7',
        '1,7','2,7','3,7','4,7','5,7','6,7','7,7','8,7','9,7','10,7','11,7',
        '1,1','2,1','3,1','1,2','2,2','3,2',
        '9,1','10,1','11,1','11,2'
      ],
      warpEvents: [
        { x: 10, y: 2, elevation: 3, dest_map: 'PalletTown_PlayersHouse_1F', dest_warp_id: 2 }
      ],
      objectEvents: [],
      bgEvents: [
        { type: 'sign', x: 6, y: 5, script: 'PalletTown_PlayersHouse_2F_EventScript_NES' },
        { type: 'sign', x: 1, y: 1, script: 'PalletTown_PlayersHouse_2F_EventScript_PC' },
        { type: 'sign', x: 11, y: 1, script: 'PalletTown_PlayersHouse_2F_EventScript_Sign' }
      ],
      structures: {
        bed: { x: 1, y: 1, w: 3, h: 2 },
        pc: { x: 9, y: 1, w: 2, h: 2 },
        shelf: { x: 11, y: 1, w: 1, h: 2 },
        stairs: { x: 10, y: 2, w: 1, h: 1 }
      }
    },
    PalletTown_PlayersHouse_1F: {
      id: 'PalletTown_PlayersHouse_1F',
      label: 'PalletTown_PlayersHouse_1F',
      mapType: 'MAP_TYPE_INDOOR',
      width: 12,
      height: 10,
      start: { x: 6, y: 5, dir: 'down' },
      blocked: [
        '0,0','1,0','2,0','3,0','4,0','5,0','6,0','7,0','8,0','9,0','10,0','11,0',
        '0,1','0,2','0,3','0,4','0,5','0,6','0,7','0,8','0,9',
        '11,1','11,2','11,3','11,4','11,5','11,6','11,7','11,8','11,9',
        '1,9','2,9','6,9','7,9','8,9','9,9','10,9',
        '1,1','2,1','3,1','4,1','5,1','1,2','2,2','3,2','4,2','5,2',
        '8,1','9,1','10,1','9,2',
        '7,4','8,4','9,4','7,5','9,5'
      ],
      warpEvents: [
        { x: 5, y: 8, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 0 },
        { x: 4, y: 8, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 0 },
        { x: 10, y: 2, elevation: 3, dest_map: 'PalletTown_PlayersHouse_2F', dest_warp_id: 0 },
        { x: 3, y: 9, elevation: 0, dest_map: 'PalletTown', dest_warp_id: 0 }
      ],
      objectEvents: [
        { local_id: 'LOCALID_MOM', graphics_id: 'OBJ_EVENT_GFX_MOM', x: 8, y: 4, script: 'PalletTown_PlayersHouse_1F_EventScript_Mom' }
      ],
      bgEvents: [
        { type: 'sign', x: 6, y: 1, script: 'PalletTown_PlayersHouse_1F_EventScript_TV' }
      ],
      structures: {
        kitchen: { x: 1, y: 1, w: 5, h: 2 },
        tv: { x: 6, y: 1, w: 1, h: 1 },
        table: { x: 7, y: 4, w: 3, h: 2 },
        stairs: { x: 10, y: 2, w: 1, h: 1 },
        door: { x: 4, y: 8, w: 2, h: 1 }
      }
    },

    PalletTown_RivalsHouse: {
      id: 'PalletTown_RivalsHouse',
      label: 'PalletTown_RivalsHouse',
      mapType: 'MAP_TYPE_INDOOR',
      width: 14,
      height: 10,
      start: { x: 4, y: 7, dir: 'up' },
      blocked: [
        '0,0','1,0','2,0','3,0','4,0','5,0','6,0','7,0','8,0','9,0','10,0','11,0','12,0','13,0',
        '0,1','0,2','0,3','0,4','0,5','0,6','0,7','0,8','0,9',
        '13,1','13,2','13,3','13,4','13,5','13,6','13,7','13,8','13,9',
        '0,9','1,9','2,9','6,9','7,9','8,9','9,9','10,9','11,9','12,9','13,9',
        '1,1','2,1','3,1','4,1','5,1','1,2','2,2','3,2','4,2','5,2',
        '9,1','10,1','11,1','12,1','10,2','11,2','12,2',
        '10,5','11,5','12,5','12,6'
      ],
      warpEvents: [
        { x: 4, y: 8, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 1 },
        { x: 5, y: 8, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 1 },
        { x: 3, y: 8, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 1 }
      ],
      objectEvents: [
        { local_id: 'LOCALID_DAISY', graphics_id: 'OBJ_EVENT_GFX_DAISY', x: 10, y: 6, script: 'PalletTown_RivalsHouse_EventScript_Daisy' },
        { local_id: 'LOCALID_TOWN_MAP', graphics_id: 'OBJ_EVENT_GFX_TOWN_MAP', x: 6, y: 4, script: 'PalletTown_RivalsHouse_EventScript_TownMap' }
      ],
      bgEvents: [
        { type: 'sign', x: 12, y: 1, script: 'PalletTown_RivalsHouse_EventScript_Bookshelf' },
        { type: 'sign', x: 11, y: 1, script: 'PalletTown_RivalsHouse_EventScript_Bookshelf' },
        { type: 'sign', x: 9, y: 1, script: 'PalletTown_RivalsHouse_EventScript_Picture' }
      ],
      structures: {
        entryDoor: { x: 3, y: 8, w: 3, h: 1 },
        desk: { x: 9, y: 1, w: 4, h: 2 },
        shelf: { x: 1, y: 1, w: 5, h: 2 },
        mapStand: { x: 6, y: 4, w: 1, h: 1 },
        sofa: { x: 10, y: 5, w: 3, h: 2 }
      }
    },
    PalletTown_ProfessorOaksLab: {
      id: 'PalletTown_ProfessorOaksLab',
      label: 'PalletTown_ProfessorOaksLab',
      mapType: 'MAP_TYPE_INDOOR',
      width: 14,
      height: 14,
      start: { x: 6, y: 11, dir: 'up' },
      blocked: [
        '0,0','1,0','2,0','3,0','4,0','5,0','6,0','7,0','8,0','9,0','10,0','11,0','12,0','13,0',
        '0,1','0,2','0,3','0,4','0,5','0,6','0,7','0,8','0,9','0,10','0,11','0,12','0,13',
        '13,1','13,2','13,3','13,4','13,5','13,6','13,7','13,8','13,9','13,10','13,11','13,12','13,13',
        '0,13','1,13','2,13','3,13','8,13','9,13','10,13','11,13','12,13','13,13',
        '2,1','3,1','4,1','5,1','8,1','9,1','10,1','11,1',
        '3,4','4,4','5,4','11,4',
        '1,9','2,9','3,9','10,9','11,9','12,9'
      ],
      warpEvents: [
        { x: 6, y: 12, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 2 },
        { x: 7, y: 12, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 2 },
        { x: 5, y: 12, elevation: 3, dest_map: 'PalletTown', dest_warp_id: 2 }
      ],
      objectEvents: [
        { graphics_id: 'OBJ_EVENT_GFX_SCIENTIST', x: 3, y: 11, script: 'PalletTown_ProfessorOaksLab_EventScript_Aide1' },
        { graphics_id: 'OBJ_EVENT_GFX_WORKER_F', x: 2, y: 10, script: 'PalletTown_ProfessorOaksLab_EventScript_Aide3' },
        { graphics_id: 'OBJ_EVENT_GFX_SCIENTIST', x: 11, y: 10, script: 'PalletTown_ProfessorOaksLab_EventScript_Aide2' },
        { local_id: 'LOCALID_OAKS_LAB_PROF_OAK', graphics_id: 'OBJ_EVENT_GFX_PROF_OAK', x: 6, y: 3, script: 'PalletTown_ProfessorOaksLab_EventScript_ProfOak' },
        { local_id: 'LOCALID_BULBASAUR_BALL', graphics_id: 'OBJ_EVENT_GFX_ITEM_BALL', x: 8, y: 4, script: 'PalletTown_ProfessorOaksLab_EventScript_BulbasaurBall' },
        { local_id: 'LOCALID_SQUIRTLE_BALL', graphics_id: 'OBJ_EVENT_GFX_ITEM_BALL', x: 9, y: 4, script: 'PalletTown_ProfessorOaksLab_EventScript_SquirtleBall' },
        { local_id: 'LOCALID_CHARMANDER_BALL', graphics_id: 'OBJ_EVENT_GFX_ITEM_BALL', x: 10, y: 4, script: 'PalletTown_ProfessorOaksLab_EventScript_CharmanderBall' },
        { local_id: 'LOCALID_OAKS_LAB_RIVAL', graphics_id: 'OBJ_EVENT_GFX_BLUE', x: 5, y: 4, script: 'PalletTown_ProfessorOaksLab_EventScript_Rival' },
        { local_id: 'LOCALID_POKEDEX_1', graphics_id: 'OBJ_EVENT_GFX_POKEDEX', x: 4, y: 1, script: 'PalletTown_ProfessorOaksLab_EventScript_Pokedex' },
        { local_id: 'LOCALID_POKEDEX_2', graphics_id: 'OBJ_EVENT_GFX_POKEDEX', x: 5, y: 1, script: 'PalletTown_ProfessorOaksLab_EventScript_Pokedex' }
      ],
      bgEvents: [
        { type: 'sign', x: 2, y: 1, script: 'PalletTown_ProfessorOaksLab_EventScript_Computer' },
        { type: 'sign', x: 3, y: 1, script: 'PalletTown_ProfessorOaksLab_EventScript_Computer' },
        { type: 'sign', x: 6, y: 1, script: 'PalletTown_ProfessorOaksLab_EventScript_LeftSign' },
        { type: 'sign', x: 7, y: 1, script: 'PalletTown_ProfessorOaksLab_EventScript_RightSign' }
      ],
      structures: {
        leftBench: { x: 2, y: 1, w: 4, h: 1 },
        rightBench: { x: 8, y: 1, w: 4, h: 1 },
        starterTable: { x: 5, y: 4, w: 6, h: 1 },
        leftShelves: { x: 1, y: 9, w: 3, h: 1 },
        rightShelves: { x: 10, y: 9, w: 3, h: 1 },
        door: { x: 5, y: 12, w: 3, h: 1 }
      }
    },
    PalletTown: {
      id: 'PalletTown',
      label: 'PalletTown',
      mapType: 'MAP_TYPE_TOWN',
      width: 22,
      height: 18,
      start: { x: 8, y: 9, dir: 'down' },
      blocked: [
        '0,0','1,0','2,0','3,0','4,0','5,0','6,0','7,0','8,0','9,0','10,0','11,0','12,0','13,0','14,0','15,0','16,0','17,0','18,0','19,0','20,0','21,0',
        '0,17','1,17','2,17','3,17','4,17','5,17','6,17','7,17','8,17','9,17','10,17','11,17','12,17','13,17','14,17','15,17','16,17','17,17','18,17','19,17','20,17','21,17',
        '0,1','0,2','0,3','0,4','0,5','0,6','0,7','0,8','0,9','0,10','0,11','0,12','0,13','0,14','0,15','0,16',
        '21,1','21,2','21,3','21,4','21,5','21,6','21,7','21,8','21,9','21,10','21,11','21,12','21,13','21,14','21,15','21,16',
        '5,4','6,4','7,4','5,5','6,5','7,5','5,6','7,6',
        '14,4','15,4','16,4','14,5','15,5','16,5','14,6','15,6','16,6',
        '15,10','16,10','17,10','15,11','16,11','17,11','15,12','17,12',
        '9,6','10,6','11,6','12,6','9,7','10,7','11,7','12,7','9,8','10,8','11,8','12,8'
      ],
      warpEvents: [
        { x: 6, y: 7, elevation: 0, dest_map: 'PalletTown_PlayersHouse_1F', dest_warp_id: 1 },
        { x: 15, y: 7, elevation: 0, dest_map: 'PalletTown_Rivals_House', dest_warp_id: 0 },
        { x: 16, y: 13, elevation: 0, dest_map: 'PalletTown_ProfessorOaksLab', dest_warp_id: 0 }
      ],
      objectEvents: [
        { local_id: 'LOCALID_PALLET_SIGN_LADY', graphics_id: 'OBJ_EVENT_GFX_WOMAN_1', x: 3, y: 10, script: 'PalletTown_EventScript_SignLady' },
        { local_id: 'LOCALID_PALLET_FAT_MAN', graphics_id: 'OBJ_EVENT_GFX_FAT_MAN', x: 13, y: 17, script: 'PalletTown_EventScript_FatMan' },
        { local_id: 'LOCALID_PALLET_PROF_OAK', graphics_id: 'OBJ_EVENT_GFX_PROF_OAK', x: 10, y: 8, script: '0x0', hiddenByFlag: 'FLAG_HIDE_OAK_IN_PALLET_TOWN' }
      ],
      bgEvents: [
        { type: 'sign', x: 16, y: 16, script: 'PalletTown_EventScript_OaksLabSign' },
        { type: 'sign', x: 4, y: 7, script: 'PalletTown_EventScript_PlayersHouseSign' },
        { type: 'sign', x: 13, y: 7, script: 'PalletTown_EventScript_RivalsHouseSign' },
        { type: 'sign', x: 9, y: 11, script: 'PalletTown_EventScript_TownSign' },
        { type: 'sign', x: 5, y: 14, script: 'PalletTown_EventScript_TrainerTipsSign' }
      ],
      structures: {
        playerHouse: { x: 5, y: 4, w: 3, h: 3 },
        rivalHouse: { x: 14, y: 4, w: 3, h: 3 },
        oaksLab: { x: 15, y: 10, w: 3, h: 3 },
        northBlock: { x: 9, y: 6, w: 4, h: 3 },
        path: [
          { x: 5, y: 7, w: 3, h: 6 },
          { x: 13, y: 7, w: 3, h: 6 },
          { x: 7, y: 11, w: 9, h: 2 },
          { x: 14, y: 12, w: 3, h: 3 }
        ]
      }
    }
  }
};
